<?php
declare(strict_types=1);

require_once __DIR__ . '/../Utilities/Logger.php';

class GrafanaProxyClient implements GrafanaClientInterface
{
    private string $grafanaUrl;
    private string $apiToken;
    private Logger $logger;
    private array $metricsCache = [];
    private array $headers;
    private array $blacklistDatasourceIds; // New property for blacklisted datasource IDs
    private CacheManagerInterface $cacheManager;

    /** тип последнего datasource, на который сделали queryRange */
    private string $lastDataSourceType = 'unknown';

    public function __construct(
        string $grafanaUrl,
        string $apiToken,
        LoggerInterface $logger,
        array $blacklistDatasourceIds = [],
        ?CacheManagerInterface $cacheManager = null
    ) {
        $this->grafanaUrl = rtrim($grafanaUrl, '/');
        $this->apiToken   = $apiToken;
        $this->logger     = $logger;
        $this->blacklistDatasourceIds = $blacklistDatasourceIds; // Initialize blacklist
        $this->cacheManager = $cacheManager;
        $this->headers    = [
            "Authorization: Bearer {$this->apiToken}",
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $this->loadMetricsCache();
    }

    /**
     * Возвращает перечень доступных “metrics” (dashboard__panel ключи).
     * (вызывается из index.php для /api/v1/labels и /api/v1/label/__name__/values)
     */
    public function getMetricNames(): array
    {
        return array_keys($this->metricsCache);
    }

    /**
     * Отдаёт тип последнего datasource, на который мы делали queryRange.
     */
    public function getLastDataSourceType(): string
    {
        return $this->lastDataSourceType;
    }

    /**
     * Запрашивает у Grafana /api/ds/query и возвращает
     * массив точек ['time'=>'Y-m-d H:i:s','value'=>float,'labels'=>[]].
     */
    public function queryRange(string $metricName, int $start, int $end, int $step): array
    {
        if (!isset($this->metricsCache[$metricName])) {
            $this->logger->error("Метрика не найдена в кэше Grafana: $metricName", __FILE__, __LINE__);
            return [];
        }

        $info = $this->metricsCache[$metricName];

        // 1) Получаем JSON дашборда
        $dashJson = $this->httpRequest('GET', "{$this->grafanaUrl}/api/dashboards/uid/{$info['dashboard_uid']}");
        if (!$dashJson) {
            $this->logger->error("Не удалось получить JSON дашборда {$info['dashboard_uid']}", __FILE__, __LINE__);
            return [];
        }

        $dashData = json_decode($dashJson, true);
        $panels   = $dashData['dashboard']['panels'] ?? [];
        $target   = null;
        foreach ($panels as $p) {
            if ((string)$p['id'] === $info['panel_id']) {
                $target = $p;
                break;
            }
        }

        if (!$target || empty($target['targets'])) {
            $this->logger->warn("Панель {$info['panel_id']} не содержит targets", __FILE__, __LINE__);
            return [];
        }

        // 2) Определяем тип datasource из первого target
        if (isset($target['targets'][0]['datasource'])) {
            $ds = $target['targets'][0]['datasource'];
            $this->lastDataSourceType = strtolower($ds['type'] ?? $ds['name'] ?? 'unknown');
        } else {
            $this->lastDataSourceType = 'unknown';
        }

        // 3) Формируем запрос к /api/ds/query
        $fromMs = $start * 1000;
        $toMs   = $end   * 1000;
        $stepMs = (int)($step * 1000);

        $queries = [];
        foreach ($target['targets'] as $t) {
            $q = [
                'refId'         => $t['refId']         ?? 'A',
                'datasource'    => $t['datasource']    ?? [],
                'format'        => $t['format']        ?? 'time_series',
                'intervalMs'    => $stepMs,
                'maxDataPoints' => ceil(($toMs - $fromMs) / $stepMs),
            ];
            foreach ($t as $k => $v) {
                if (!isset($q[$k])) {
                    $q[$k] = $v;
                }
            }
            $queries[] = $q;
        }

        $body = json_encode([
            'from'    => (string)$fromMs,
            'to'      => (string)$toMs,
            'queries' => $queries,
        ]);

        $resp = $this->httpRequest('POST', "{$this->grafanaUrl}/api/ds/query", $body);
        if (!$resp) {
            $this->logger->error("DS query для $metricName завершился ошибкой", __FILE__, __LINE__);
            return [];
        }

        return $this->parseFrames(json_decode($resp, true), $info);
    }

    /**
     * Загружаем $metricsCache из кэша.
     */
    private function loadMetricsCache(): void
    {
        $cached = $this->cacheManager->loadGrafanaMetrics();
        if ($cached !== null) {
            $this->metricsCache = $cached;
            $this->logger->info(
                "Кэш метрик Grafana загружен: " . implode(', ', array_keys($this->metricsCache)),
                __FILE__,
                __LINE__
            );
        } else {
            $this->metricsCache = [];
            $this->logger->warn("Кэш метрик Grafana не найден, метрики будут пустыми до обновления", __FILE__, __LINE__);
        }
    }

    /**
     * Обновляем $metricsCache из Grafana и сохраняем в кэш.
     */
    public function updateMetricsCache(): void
    {
        $this->initMetricsCache();
        if ($this->cacheManager->saveGrafanaMetrics($this->metricsCache)) {
            $this->logger->info(
                "Кэш метрик Grafana обновлен и сохранен: " . implode(', ', array_keys($this->metricsCache)),
                __FILE__,
                __LINE__
            );
        } else {
            $this->logger->error("Ошибка сохранения кэша метрик Grafana после обновления", __FILE__, __LINE__);
        }
    }

    /**
     * Инициализируем $metricsCache из всех дашбордов Grafana (используется только в updateMetricsCache).
     */
    private function initMetricsCache(): void
    {
        $resp = $this->httpRequest('GET', "{$this->grafanaUrl}/api/search?type=dash-db");
        if (!$resp) {
            $this->logger->error("Не удалось получить список дашбордов", __FILE__, __LINE__);
            return;
        }

        $dashboards = json_decode($resp, true);
        $this->metricsCache = []; // Reset
        foreach ($dashboards as $dash) {
            $uid   = $dash['uid'];
            $title = $dash['title'] ?: $uid;
            $dashJson = $this->httpRequest('GET', "{$this->grafanaUrl}/api/dashboards/uid/$uid");
            if (!$dashJson) {
                $this->logger->warn("Не удалось загрузить дашборд $uid", __FILE__, __LINE__);
                continue;
            }
            $dashData = json_decode($dashJson, true);
            $panels = $dashData['dashboard']['panels'] ?? [];
            foreach ($panels as $p) {
                if (empty($p['id'])) {
                    continue;
                }
                if (empty($p['targets'])) {
                    $this->logger->debug("Пропущена панель {$p['id']} в дашборде $uid: без targets", __FILE__, __LINE__);
                    continue;
                }
                // Check if the panel's datasource is in the blacklist
                $datasourceId = $p['targets'][0]['datasource']['uid'] ?? null;
                if ($datasourceId && in_array($datasourceId, $this->blacklistDatasourceIds)) {
                    $this->logger->info("Пропущена панель {$p['id']} в дашборде $uid: datasource $datasourceId в черном списке", __FILE__, __LINE__);
                    continue;
                }
                $panelId    = (string)$p['id'];
                $panelTitle = $p['title'] ?: "Panel_$panelId";
                $key = "{$title}__{$panelTitle}";
                $this->metricsCache[$key] = [
                    'dashboard_uid' => $uid,
                    'panel_id'      => $panelId,
                    'dash_title'    => $title,
                    'panel_title'   => $panelTitle,
                ];
            }
        }

        $this->logger->info(
            "Кэш метрик Grafana инициализирован (временный): " . implode(', ', array_keys($this->metricsCache)),
            __FILE__,
            __LINE__
        );
    }

    /**
     * Выполняет HTTP-запрос к Grafana и возвращает тело или null.
     */
    private function httpRequest(string $method, string $url, ?string $body = null): ?string
    {
        $this->logger->info("Grafana HTTP Request → $method $url\nBody: " . ($body ?? 'none'), __FILE__, __LINE__);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err || $code >= 400) {
            $this->logger->error("Grafana HTTP Error → $method $url\nCode: $code, Error: " . ($err ?: 'HTTP status >= 400'), __FILE__, __LINE__);
            return null;
        }

        $this->logger->info("Grafana HTTP Response ← Code: $code\nBody (truncated): " . substr($resp ?? '', 0, 1000), __FILE__, __LINE__);
        return $resp;
    }

    /**
     * Конвертирует Grafana frames → Prometheus-like array.
     */
    private function parseFrames(array $data, array $info): array
    {
        $out     = [];
        $results = $data['results'] ?? [];
        foreach ($results as $frameSet) {
            foreach ($frameSet['frames'] as $frame) {
                $times  = $frame['data']['values'][0];
                $fields = $frame['schema']['fields'];
                for ($i = 1, $n = count($fields); $i < $n; $i++) {
                    $vals   = $frame['data']['values'][$i];
                    $labels = $fields[$i]['labels'] ?? [];
                    $labels['__name__'] = $info['dash_title'] . '__' . $info['panel_title'];
                    $labels['panel_url'] = sprintf(
                        '%s/d/%s/%s?viewPanel=%s',
                        $this->grafanaUrl,
                        $info['dashboard_uid'],
                        rawurlencode($info['dash_title']),
                        $info['panel_id']
                    );
                    foreach ($times as $idx => $ts) {
                        if ($vals[$idx] === null) {
                            continue;
                        }
                        $out[] = [
                            'time'   => date('Y-m-d H:i:s', intval($ts / 1000)),
                            'value'  => (float)$vals[$idx],
                            'labels' => $labels,
                        ];
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Создает дашборд с оверрайдом для опасной метрики в указанной папке.
     * Возвращает URL дашборда или false при ошибке.
     */
    public function createDangerDashboard(string $metricName, string $folderUid): string|false
    {
        if (!isset($this->metricsCache[$metricName])) {
            $this->logger->error("Метрика не найдена в кэше: $metricName", __FILE__, __LINE__);
            return false;
        }

        $info = $this->metricsCache[$metricName];

        // Получаем JSON дашборда
        $dashJson = $this->httpRequest('GET', "{$this->grafanaUrl}/api/dashboards/uid/{$info['dashboard_uid']}");
        if (!$dashJson) {
            $this->logger->error("Не удалось получить JSON дашборда {$info['dashboard_uid']}", __FILE__, __LINE__);
            return false;
        }

        $dashData = json_decode($dashJson, true);
        $panels = $dashData['dashboard']['panels'] ?? [];
        $targetPanel = null;
        foreach ($panels as $p) {
            if ((string)$p['id'] === $info['panel_id']) {
                $targetPanel = $p;
                break;
            }
        }

        if (!$targetPanel || empty($targetPanel['targets'])) {
            $this->logger->warn("Панель {$info['panel_id']} не найдена или без targets", __FILE__, __LINE__);
            return false;
        }

        $originalExpr = $targetPanel['targets'][0]['expr'] ?? '';
        if (empty($originalExpr)) {
            $this->logger->warn("Expr не найден в панели {$info['panel_id']} для создания danger dashboard, пропуск", __FILE__, __LINE__);
            return false;
        }

        // URL оригинальной панели
        $panelUrl = sprintf(
            '%s/d/%s/%s?viewPanel=%s',
            $this->grafanaUrl,
            $info['dashboard_uid'],
            rawurlencode($info['dash_title']),
            $info['panel_id']
        );

        // Шаблон панели из задачи
        $panelTemplate = '{
  "datasource": {
    "type": "prometheus",
    "uid": "ceh9u3ymlpdz4b"
  },
  "fieldConfig": {
    "defaults": {
      "custom": {
        "drawStyle": "line",
        "lineInterpolation": "linear",
        "barAlignment": 0,
        "lineWidth": 2,
        "fillOpacity": 0,
        "gradientMode": "none",
        "spanNulls": false,
        "insertNulls": false,
        "showPoints": "auto",
        "pointSize": 5,
        "stacking": {
          "mode": "none",
          "group": "A"
        },
        "axisPlacement": "auto",
        "axisLabel": "",
        "axisColorMode": "text",
        "axisBorderShow": false,
        "scaleDistribution": {
          "type": "linear"
        },
        "axisCenteredZero": false,
        "hideFrom": {
          "tooltip": false,
          "viz": false,
          "legend": false
        },
        "thresholdsStyle": {
          "mode": "off"
        }
      },
      "color": {
        "mode": "palette-classic"
      },
      "mappings": [],
      "thresholds": {
        "mode": "absolute",
        "steps": [
          {
            "color": "green",
            "value": null
          },
          {
            "color": "red",
            "value": 80
          }
        ]
      }
    },
    "overrides": [
      {
        "matcher": {
          "id": "byName",
          "options": "dft_lower"
        },
        "properties": [
          {
            "id": "custom.lineWidth",
            "value": 0
          },
          {
            "id": "custom.stacking",
            "value": {
              "group": "A",
              "mode": "normal"
            }
          }
        ]
      },
      {
        "matcher": {
          "id": "byName",
          "options": "dft_range"
        },
        "properties": [
          {
            "id": "custom.stacking",
            "value": {
              "group": "A",
              "mode": "normal"
            }
          },
          {
            "id": "custom.fillOpacity",
            "value": 23
          },
          {
            "id": "custom.fillBelowTo",
            "value": "dft_lower"
          },
          {
            "id": "custom.lineWidth",
            "value": 0
          },
          {
            "id": "color",
            "value": {
              "mode": "fixed"
            }
          }
        ]
      },
      {
        "__systemRef": "hideSeriesFrom",
        "matcher": {
          "id": "byNames",
          "options": {
            "mode": "exclude",
            "names": [
              "{__name__=\"original\", original_query=\"PLACEHOLDER_QUERY\", panel_url=\"PLACEHOLDER_URL\"}",
              "{__name__=\"dft_lower\", original_query=\"PLACEHOLDER_QUERY\", panel_url=\"PLACEHOLDER_URL\"}",
              "{__name__=\"dft_range\", original_query=\"PLACEHOLDER_QUERY\", panel_url=\"PLACEHOLDER_URL\"}"
            ],
            "prefix": "All except:",
            "readOnly": true
          }
        },
        "properties": [
          {
            "id": "custom.hideFrom",
            "value": {
              "legend": false,
              "tooltip": false,
              "viz": true
            }
          }
        ]
      }
    ]
  },
  "gridPos": {
    "h": 12,
    "w": 24,
    "x": 0,
    "y": 12
  },
  "id": 2,
  "options": {
    "tooltip": {
      "mode": "single",
      "sort": "none",
      "maxHeight": 600
    },
    "legend": {
      "showLegend": true,
      "displayMode": "table",
      "placement": "bottom",
      "calcs": [
        "lastNotNull"
      ]
    }
  },
  "targets": [
    {
      "datasource": {
        "type": "prometheus",
        "uid": "ceh9u3ymlpdz4b"
      },
      "disableTextWrap": false,
      "editorMode": "builder",
      "expr": "PLACEHOLDER_EXPR",
      "fullMetaSearch": false,
      "includeNullMetadata": true,
      "instant": false,
      "legendFormat": "__auto",
      "range": true,
      "refId": "A",
      "useBackend": false
    }
  ],
  "title": "PLACEHOLDER_TITLE",
  "type": "timeseries"
}';

        // Заменяем плейсхолдеры
        $panelConfig = json_decode(str_replace(
            ['PLACEHOLDER_EXPR', 'PLACEHOLDER_QUERY', 'PLACEHOLDER_URL', 'PLACEHOLDER_TITLE'],
            [$originalExpr, $metricName, $panelUrl, "Danger Alert: $metricName"],
            $panelTemplate
        ), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error("Ошибка парсинга panel config: " . json_last_error_msg(), __FILE__, __LINE__);
            return false;
        }

        // Новый дашборд
        $newDashboard = [
            'dashboard' => [
                'title' => "Danger Alert for $metricName",
                'panels' => [$panelConfig],
                'uid' => null,
                'folderUid' => $folderUid,
                'tags' => ['danger-alert'],
                'time' => [
                    'from' => 'now-6h',
                    'to' => 'now'
                ],
                'timezone' => 'browser',
                'refresh' => '5s',
                'schemaVersion' => 39,
                'version' => 1,
                'links' => [],
                'style' => 'dark',
                'fiscalYearStartMonth' => 0,
                'liveNow' => false,
                'weekStart' => ''
            ],
            'folderUid' => $folderUid,
            'overwrite' => false
        ];

        $body = json_encode($newDashboard);
        $resp = $this->httpRequest('POST', "{$this->grafanaUrl}/api/dashboards/db", $body);
        if (!$resp) {
            $this->logger->error("Ошибка создания дашборда для $metricName", __FILE__, __LINE__);
            return false;
        }

        $responseData = json_decode($resp, true);
        $uid = $responseData['uid'] ?? null;
        if (!$uid) {
            $this->logger->error("UID не получен при создании дашборда: " . json_encode($responseData), __FILE__, __LINE__);
            return false;
        }

        $dashboardTitle = $responseData['title'] ?? "Danger Alert for $metricName";
        $dashboardUrl = $this->grafanaUrl . "/d/" . $uid . "/" . rawurlencode($dashboardTitle);
        $this->logger->info("Создан дашборд для опасной метрики $metricName: $dashboardUrl", __FILE__, __LINE__);
        return $dashboardUrl;
    }

    /**
     * Возвращает PromQL запрос (expr) для указанной метрики.
     */
    public function getQueryForMetric(string $metricName): string|false
    {
        if (!isset($this->metricsCache[$metricName])) {
            $this->logger->error("Метрика не найдена в кэше: $metricName", __FILE__, __LINE__);
            return false;
        }

        $info = $this->metricsCache[$metricName];

        // Получаем JSON дашборда
        $dashJson = $this->httpRequest('GET', "{$this->grafanaUrl}/api/dashboards/uid/{$info['dashboard_uid']}");
        if (!$dashJson) {
            $this->logger->error("Не удалось получить JSON дашборда {$info['dashboard_uid']}", __FILE__, __LINE__);
            return false;
        }

        $dashData = json_decode($dashJson, true);
        $panels = $dashData['dashboard']['panels'] ?? [];
        $targetPanel = null;
        foreach ($panels as $p) {
            if ((string)$p['id'] === $info['panel_id']) {
                $targetPanel = $p;
                break;
            }
        }

        if (!$targetPanel || empty($targetPanel['targets'])) {
            $this->logger->warn("Панель {$info['panel_id']} не найдена или без targets", __FILE__, __LINE__);
            return false;
        }

        $expr = $targetPanel['targets'][0]['expr'] ?? '';
        if (empty($expr)) {
            $this->logger->error("Expr не найден в панели {$info['panel_id']}", __FILE__, __LINE__);
            return false;
        }

        $this->logger->info("Получен expr для $metricName: $expr", __FILE__, __LINE__);
        return $expr;
    }
}
?>