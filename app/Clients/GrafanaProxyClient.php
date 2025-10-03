<?php
declare(strict_types=1);

namespace App\Clients;

use App\Interfaces\LoggerInterface;
use App\Interfaces\GrafanaClientInterface;
use App\Interfaces\CacheManagerInterface;
use App\Cache\CacheManagerInterface as CacheManager; // Wait, no, it's App\Interfaces\CacheManagerInterface

class GrafanaProxyClient implements GrafanaClientInterface
{
    private string $grafanaUrl;
    private string $apiToken;
    private LoggerInterface $logger;
    private array $metricsCache = [];
    private array $headers;
    private array $blacklistDatasourceIds; // New property for blacklisted datasource IDs
    private CacheManagerInterface $cacheManager;
    private array $dashCache = [];
    private int $instanceId;

    /** тип последнего datasource, на который сделали queryRange */
    private string $lastDataSourceType = 'unknown';

    public function __construct(
        array $instance,
        LoggerInterface $logger,
        ?CacheManagerInterface $cacheManager = null
    ) {
        $this->instanceId = $instance['id'];
        $this->logger     = $logger;
        $this->cacheManager = $cacheManager;

        $this->loadInstanceData();
        $this->headers    = [
            "Authorization: Bearer {$this->apiToken}",
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $this->loadMetricsCache();
    }

    /**
     * Загружает данные инстанса из базы данных.
     */
    private function loadInstanceData(): void
    {
        $instanceData = $this->cacheManager->getGrafanaInstanceById($this->instanceId);
        if (!$instanceData) {
            $this->logger->error("Не удалось загрузить данные инстанса Grafana с ID {$this->instanceId}");
            throw new \Exception("Instance data not found for ID {$this->instanceId}");
        }
        $this->grafanaUrl = rtrim($instanceData['url'], '/');
        $this->apiToken   = $instanceData['token'];
        $this->blacklistDatasourceIds = $instanceData['blacklist_uids'];
    }

    /**
     * Возвращает перечень доступных "metrics" (dashboard__panel ключи).
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
            $this->logger->error("Метрика не найдена в кэше Grafana: $metricName");
            return [];
        }

        $info = $this->metricsCache[$metricName];

        // 1) Получаем JSON дашборда (с in-request кэшем)
        $dashData = $this->fetchDashboardData($info['dashboard_uid']);
        if (!$dashData) {
            $this->logger->error("Не удалось получить JSON дашборда {$info['dashboard_uid']}");
            return [];
        }
        $panels   = $dashData['dashboard']['panels'] ?? [];
        $target   = null;
        foreach ($panels as $p) {
            if ((string)$p['id'] === $info['panel_id']) {
                $target = $p;
                break;
            }
        }

        if (!$target || empty($target['targets'])) {
            $this->logger->warning("Панель {$info['panel_id']} не содержит targets");
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
        if (empty($queries)) {
            $this->logger->error("Нет targets для метрики $metricName – возвращаем пустой результат");
            return [];
        }

        $body = json_encode([
            'from'    => (string)$fromMs,
            'to'      => (string)$toMs,
            'queries' => $queries,
        ]);
        $this->logger->info("DS query body for $metricName (type: {$this->lastDataSourceType}): " . substr($body, 0, 500));

        $resp = $this->httpRequest('POST', "{$this->grafanaUrl}/api/ds/query", $body);
        if (!$resp) {
            $this->logger->error("DS query для $metricName завершился ошибкой");
            return [];
        }

        $parsed = $this->parseFrames(json_decode($resp, true), $info);
        if (empty($parsed)) {
            $this->logger->warning("Parsed DS query result is empty for $metricName (possible no data or parse error)");
        }
        return $parsed;
    }

    /**
     * Загружаем $metricsCache из кэша.
     */
    private function loadMetricsCache(): void
    {
        $cached = $this->cacheManager->loadGrafanaIndividualMetrics($this->instanceId);
        if (!empty($cached)) {
            $this->metricsCache = [];
            foreach ($cached as $metric) {
                $this->metricsCache[$metric['metric_key']] = $metric['metric_data'];
            }
            $this->logger->info("Кэш метрик Grafana загружен для instance {$this->instanceId}: " . implode(', ', array_keys($this->metricsCache)));
        } else {
            $this->metricsCache = [];
            $this->logger->warning("Кэш метрик Grafana не найден для instance {$this->instanceId}, метрики будут пустыми до обновления");
        }
    }

    public function reloadMetricsCache(): void
    {
        $this->loadMetricsCache();
    }

    /**
     * Обновляем $metricsCache из Grafana и сохраняем в кэш.
     */
    public function updateMetricsCache(): void
    {
        $this->initMetricsCache();
        $metrics = [];
        foreach ($this->metricsCache as $key => $data) {
            $metrics[] = [
                'metric_key' => $key,
                'metric_data' => $data
            ];
        }
        if ($this->cacheManager->updateGrafanaIndividualMetrics($this->instanceId, $metrics)) {
            $this->logger->info("Кэш метрик Grafana обновлен и сохранен для instance {$this->instanceId}: " . implode(', ', array_keys($this->metricsCache)));
        } else {
            $this->logger->error("Ошибка сохранения кэша метрик Grafana после обновления для instance {$this->instanceId}");
        }
    }

    /**
     * Инициализируем $metricsCache из всех дашбордов Grafana (используется только в updateMetricsCache).
     */
    private function initMetricsCache(): void
    {
        $resp = $this->httpRequest('GET', "{$this->grafanaUrl}/api/search?type=dash-db");
        if (!$resp) {
            $this->logger->error("Не удалось получить список дашбордов");
            return;
        }

        $dashboards = json_decode($resp, true);
        $this->metricsCache = []; // Reset
        foreach ($dashboards as $dash) {
            $uid   = $dash['uid'];
            $title = $dash['title'] ?: $uid;
            $dashJson = $this->httpRequest('GET', "{$this->grafanaUrl}/api/dashboards/uid/$uid");
            if (!$dashJson) {
                $this->logger->warning("Не удалось загрузить дашборд $uid");
                continue;
            }
            $dashData = json_decode($dashJson, true);
            $panels = $dashData['dashboard']['panels'] ?? [];
            foreach ($panels as $p) {
                if (empty($p['id'])) {
                    continue;
                }
                if (empty($p['targets'])) {
                    $this->logger->debug("Пропущена панель {$p['id']} в дашборде $uid: без targets");
                    continue;
                }
                // Check if the panel's datasource is in the blacklist
                $datasourceId = $p['targets'][0]['datasource']['uid'] ?? null;
                if ($datasourceId && in_array($datasourceId, $this->blacklistDatasourceIds)) {
                    $this->logger->info("Пропущена панель {$p['id']} в дашборде $uid: datasource $datasourceId в черном списке");
                    continue;
                }
                $panelId    = (string)$p['id'];
                $panelTitle = $p['title'] ?: "Panel_$panelId";
                $key = "{$title}, {$panelTitle}";
                $this->metricsCache[$key] = [
                    'dashboard_uid' => $uid,
                    'panel_id'      => $panelId,
                    'dash_title'    => $title,
                    'panel_title'   => $panelTitle,
                ];
            }
        }

        $this->logger->info("Кэш метрик Grafana инициализирован (временный): " . implode(', ', array_keys($this->metricsCache)));
    }

    /**
     * Выполняет HTTP-запрос к Grafana и возвращает тело или null.
     */
    private function httpRequest(string $method, string $url, ?string $body = null): ?string
    {
        $maxRetries = 2; // Retry up to 2 times for transient errors
        $retryCount = 0;

        while ($retryCount <= $maxRetries) {
            $this->logger->info("Grafana HTTP Request → $method $url (attempt " . ($retryCount + 1) . ")\nBody: " . ($body ?? 'none'));
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Add 10s timeout
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // Add connect timeout
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $resp = curl_exec($ch);
            $err  = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!$err && $code < 400) {
                $this->logger->info("Grafana HTTP Response ← Code: $code\nBody (truncated): " . substr($resp ?? '', 0, 1000));
                return $resp;
            }

            $errorDetails = $err ?: "HTTP status $code";
            if ($code >= 400 && $resp) {
                $errorDetails .= ", Body: " . substr($resp, 0, 500);
            }
            $this->logger->error("Grafana HTTP Error → $method $url (attempt " . ($retryCount + 1) . ")\nCode: $code, Error: $errorDetails");

            $retryCount++;
            if ($retryCount <= $maxRetries && ($err || in_array($code, [500, 502, 503, 504]))) { // Retry on curl err or 5xx
                $this->logger->info("Retrying request after " . ($retryCount - 1) . " failure(s)...");
                sleep(1 * $retryCount); // Exponential backoff: 1s, 2s
            } else {
                return null;
            }
        }

        return null;
    }

    /**
     * In-request cache для JSON дашборда.
     */
    private function fetchDashboardData(string $uid): ?array
    {
        if (isset($this->dashCache[$uid])) {
            return $this->dashCache[$uid];
        }
        $dashJson = $this->httpRequest('GET', "{$this->grafanaUrl}/api/dashboards/uid/{$uid}");
        if (!$dashJson) {
            return null;
        }
        $dashData = json_decode($dashJson, true);
        if (!is_array($dashData)) {
            return null;
        }
        $this->dashCache[$uid] = $dashData;
        return $dashData;
    }

    private function deepKsort(array &$arr): void
    {
        foreach ($arr as &$v) {
            if (is_array($v)) {
                $this->deepKsort($v);
            }
        }
        ksort($arr);
    }

    private function removeNulls(array &$arr): void
    {
        foreach ($arr as $k => &$v) {
            if (is_array($v)) {
                $this->removeNulls($v);
                if ($v === []) {
                    unset($arr[$k]);
                }
            } elseif ($v === null) {
                unset($arr[$k]);
            }
        }
    }

    private function stripVolatileFields(array &$arr, array $volatileKeys): void
    {
        foreach ($volatileKeys as $vk) {
            if (isset($arr[$vk])) {
                unset($arr[$vk]);
            }
        }
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
                if (!isset($frame['data']['values'][0]) || !isset($frame['schema']['fields'])) {
                    $this->logger->warning("Skipping frame without times or fields for metric {$info['dash_title']}, {$info['panel_title']}");
                    continue;
                }
                $times  = $frame['data']['values'][0];
                $fields = $frame['schema']['fields'];
                for ($i = 1, $n = count($fields); $i < $n; $i++) {
                    if (!isset($frame['data']['values'][$i])) {
                        continue;
                    }
                    $vals   = $frame['data']['values'][$i];
                    $labels = $fields[$i]['labels'] ?? [];
                    $labels['__name__'] = $info['dash_title'] . ', ' . $info['panel_title'];
                    $labels['panel_url'] = sprintf(
                        '%s/d/%s/%s?viewPanel=%s',
                        $this->grafanaUrl,
                        $info['dashboard_uid'],
                        rawurlencode($info['dash_title']),
                        $info['panel_id']
                    );
                    foreach ($times as $idx => $ts) {
                        if (!isset($vals[$idx]) || $vals[$idx] === null) {
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
            $this->logger->error("Метрика не найдена в кэше: $metricName");
            return false;
        }

        $info = $this->metricsCache[$metricName];

        // Получаем JSON дашборда (с in-request кэшем)
        $dashData = $this->fetchDashboardData($info['dashboard_uid']);
        if (!$dashData) {
            $this->logger->error("Не удалось получить JSON дашборда {$info['dashboard_uid']}");
            return false;
        }
        $panels = $dashData['dashboard']['panels'] ?? [];
        $targetPanel = null;
        foreach ($panels as $p) {
            if ((string)$p['id'] === $info['panel_id']) {
                $targetPanel = $p;
                break;
            }
        }

        if (!$targetPanel || empty($targetPanel['targets'])) {
            $this->logger->warning("Панель {$info['panel_id']} не найдена или без targets");
            return false;
        }

        // Для danger dashboard только Prometheus с expr
        $dsType = $targetPanel['targets'][0]['datasource']['type'] ?? '';
        if ($dsType !== 'prometheus') {
            $this->logger->info("Danger dashboard только для Prometheus, пропуск $metricName (type: $dsType)");
            return false;
        }

        // Ищем первый non-empty expr в targets
        $originalExpr = '';
        foreach ($targetPanel['targets'] as $t) {
            if (!empty($t['expr'] ?? '')) {
                $originalExpr = $t['expr'];
                break;
            }
        }
        if (empty($originalExpr)) {
            $this->logger->warning("Expr не найден в панели {$info['panel_id']} для создания danger dashboard, пропуск");
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
            $this->logger->error("Ошибка парсинга panel config: " . json_last_error_msg());
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
        $this->logger->info("Создание danger dashboard для $metricName, body length: " . strlen($body));
        $resp = $this->httpRequest('POST', "{$this->grafanaUrl}/api/dashboards/db", $body);
        if (!$resp) {
            $this->logger->error("Ошибка создания дашборда для $metricName");
            return false;
        }

        $responseData = json_decode($resp, true);
        $uid = $responseData['uid'] ?? null;
        if (!$uid) {
            $this->logger->error("UID не получен при создании дашборда: " . json_encode($responseData));
            return false;
        }
        $this->logger->info("Danger dashboard создан для $metricName, UID: $uid");

        $dashboardTitle = $responseData['title'] ?? "Danger Alert for $metricName";
        $dashboardUrl = $this->grafanaUrl . "/d/" . $uid . "/" . rawurlencode($dashboardTitle);
        $this->logger->info("Создан дашборд для опасной метрики $metricName: $dashboardUrl");
        return $dashboardUrl;
    }

    /**
     * Возвращает нормализованный md5 ядра запроса для панели метрики.
     */
    public function getNormalizedRequestMd5(string $metricName): string|false
    {
        if (!isset($this->metricsCache[$metricName])) {
            $this->logger->error("Метрика не найдена в кэше: $metricName");
            return false;
        }
        $info = $this->metricsCache[$metricName];
        $dashData = $this->fetchDashboardData($info['dashboard_uid']);
        if (!$dashData) {
            $this->logger->error("Не удалось получить JSON дашборда {$info['dashboard_uid']} для MD5");
            return false;
        }
        $panels = $dashData['dashboard']['panels'] ?? [];
        $targetPanel = null;
        foreach ($panels as $p) {
            if ((string)$p['id'] === $info['panel_id']) {
                $targetPanel = $p;
                break;
            }
        }
        if (!$targetPanel || empty($targetPanel['targets'])) {
            $this->logger->warning("Панель {$info['panel_id']} не найдена или без targets");
            return false;
        }

        // Берем первый target как канонический
        $t = $targetPanel['targets'][0];
        $ds = $t['datasource'] ?? [];
        $dsType = strtolower($ds['type'] ?? $ds['name'] ?? 'unknown');

        // Общие поля
        $core = [
            'datasource' => [
                'type' => $ds['type'] ?? null,
                'uid'  => $ds['uid']  ?? null,
            ],
        ];
        if (isset($t['editorMode'])) $core['editorMode'] = $t['editorMode'];
        if (isset($t['queryType']))  $core['queryType']  = $t['queryType'];

        // Специфика по источникам
        if ($dsType === 'prometheus') {
            if (isset($t['expr'])) $core['expr'] = $t['expr'];
        } elseif ($dsType === 'elasticsearch') {
            if (isset($t['query'])) $core['query'] = $t['query'];
            if (isset($t['timeField'])) $core['timeField'] = $t['timeField'];
            if (isset($t['bucketAggs']) && is_array($t['bucketAggs'])) {
                $bucketAggs = $t['bucketAggs'];
                // Удаляем volatile/id-поля
                foreach ($bucketAggs as &$agg) {
                    unset($agg['id'], $agg['$$hashKey']);
                }
                $core['bucketAggs'] = $bucketAggs;
            }
            if (isset($t['metrics']) && is_array($t['metrics'])) {
                $metrics = [];
                foreach ($t['metrics'] as $m) {
                    $metrics[] = [
                        'type' => $m['type'] ?? null,
                        'params' => $m['params'] ?? [],
                    ];
                }
                $core['metrics'] = $metrics;
            }
        } elseif ($dsType === 'vertamedia-clickhouse-datasource' || $dsType === 'clickhouse' || $dsType === 'grafana-clickhouse-datasource') {
            if (!empty($t['rawSql'] ?? '')) {
                $core['rawSql'] = $t['rawSql'];
            } elseif (!empty($t['builderOptions'] ?? [])) {
                $bo = $t['builderOptions'];
                $core['builder'] = [
                    'database' => $bo['database'] ?? null,
                    'table' => $bo['table'] ?? null,
                    'timeField' => $bo['timeField'] ?? null,
                    'timeFieldType' => $bo['timeFieldType'] ?? null,
                    'metrics' => [],
                    'filters' => [],
                    'groupBy' => $bo['groupBy'] ?? [],
                    'orderBy' => $bo['orderBy'] ?? [],
                    'mode' => $bo['mode'] ?? null,
                    'selectedFormat' => $bo['selectedFormat'] ?? null,
                    'queryType' => $t['queryType'] ?? null,
                ];
                if (!empty($bo['metrics'])) {
                    foreach ($bo['metrics'] as $m) {
                        $core['builder']['metrics'][] = [
                            'aggregation' => $m['aggregation'] ?? null,
                            'field' => $m['field'] ?? null,
                        ];
                    }
                }
                if (!empty($bo['filters'])) {
                    foreach ($bo['filters'] as $f) {
                        // исключаем временные фильтры
                        if (($f['type'] ?? '') === 'time') continue;
                        $core['builder']['filters'][] = [
                            'key' => $f['key'] ?? null,
                            'op'  => $f['op']  ?? null,
                            'value' => $f['value'] ?? null,
                        ];
                    }
                }
            }
        }

        // Убираем volatile-поля верхнего уровня target (если вдруг попали)
        $this->stripVolatileFields($core, [
            'from','to','intervalMs','maxDataPoints','requestId','refId','hide','datasourceId'
        ]);

        // Deep sort + удалить null-поля
        $this->removeNulls($core);
        $this->deepKsort($core);

        $json = json_encode($core, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $md5 = md5($json);
        $this->logger->debug("Normalized request core for {$metricName}: " . substr($json, 0, 500));
        return $md5;
    }

    /**
     * Возвращает PromQL запрос (expr) для указанной метрики.
     */
    public function getQueryForMetric(string $metricName): string|false
    {
        if (!isset($this->metricsCache[$metricName])) {
            $this->logger->error("Метрика не найдена в кэше: $metricName");
            return false;
        }

        $info = $this->metricsCache[$metricName];

        // Получаем JSON дашборда (с in-request кэшем)
        $dashData = $this->fetchDashboardData($info['dashboard_uid']);
        if (!$dashData) {
            $this->logger->error("Не удалось получить JSON дашборда {$info['dashboard_uid']}");
            return false;
        }
        $panels = $dashData['dashboard']['panels'] ?? [];
        $targetPanel = null;
        foreach ($panels as $p) {
            if ((string)$p['id'] === $info['panel_id']) {
                $targetPanel = $p;
                break;
            }
        }

        if (!$targetPanel || empty($targetPanel['targets'])) {
            $this->logger->warning("Панель {$info['panel_id']} не найдена или без targets");
            return false;
        }

        // Ищем первый non-empty expr в targets
        $expr = '';
        foreach ($targetPanel['targets'] as $t) {
            if (!empty($t['expr'] ?? '')) {
                $expr = $t['expr'];
                break;
            }
        }
        if (empty($expr)) {
            $this->logger->error("Expr не найден в панели {$info['panel_id']}");
            return false;
        }

        $this->logger->info("Получен expr для $metricName: $expr");
        return $expr;
    }
}
?>