<?php
declare(strict_types=1);

namespace App\Clients;

use App\Interfaces\LoggerInterface;
use App\Interfaces\GrafanaClientInterface;
use App\Interfaces\CacheManagerInterface;
use App\Interfaces\GrafanaVariableProcessorInterface;
use App\Cache\CacheManagerInterface as CacheManager; // Wait, no, it's App\Interfaces\CacheManagerInterface
use App\Utilities\HttpClient;

class GrafanaProxyClient implements GrafanaClientInterface
{
    private string $grafanaUrl;
    private string $apiToken;
    private LoggerInterface $logger;
    private array $metricsCache = [];
    private array $headers;
    private array $blacklistDatasourceIds; // New property for blacklisted datasource IDs
    private CacheManagerInterface $cacheManager;
    private GrafanaVariableProcessorInterface $variableProcessor;
    private array $dashCache = [];
    private int $instanceId;
    private HttpClient $httpClient;

    /** тип последнего datasource, на который сделали queryRange */
    private string $lastDataSourceType = 'unknown';

    public function __construct(
        array $instance,
        LoggerInterface $logger,
        ?CacheManagerInterface $cacheManager = null,
        ?GrafanaVariableProcessorInterface $variableProcessor = null
    ) {
        $this->instanceId = $instance['id'];
        $this->logger     = $logger;
        $this->cacheManager = $cacheManager;
        $this->variableProcessor = $variableProcessor;

        $this->loadInstanceData();
        $this->headers    = [
            "Authorization: Bearer {$this->apiToken}",
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        $this->httpClient = new HttpClient($this->headers, $this->logger);

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

        // Определяем тип datasource из кеша
        $this->lastDataSourceType = strtolower($info['datasource']['type'] ?? 'unknown');
        $this->logger->debug("QueryRange for $metricName: datasource type = {$this->lastDataSourceType}, substituted_query = {$info['substituted_query']}");

        // Формируем запрос к /api/ds/query с substituted_query
        $fromMs = $start * 1000;
        $toMs   = $end   * 1000;
        $stepMs = (int)($step * 1000);

        $dsType = strtolower($info['datasource']['type'] ?? 'unknown');

        $q = [
            'refId'         => 'A',
            'datasource'    => $info['datasource'],
            'format'        => 'time_series',
            'intervalMs'    => $stepMs,
            'maxDataPoints' => ceil(($toMs - $fromMs) / $stepMs),
        ];

        // Build query based on datasource type
        if ($dsType === 'elasticsearch') {
            $parsedQuery = json_decode($info['substituted_query'], true);
            if ($parsedQuery === null) {
                // It's a string, treat as Lucene query
                $q['query'] = $info['substituted_query'];
                // For time_series format, add bucketAggs and metrics
                if ($q['format'] === 'time_series') {
                    $interval = $this->calculateESInterval($stepMs);
                    $q['bucketAggs'] = [
                        [
                            'field' => '@timestamp',
                            'id' => '2',
                            'settings' => [
                                'interval' => $interval,
                                'trimEdges' => '1'
                            ],
                            'type' => 'date_histogram'
                        ]
                    ];
                    $q['metrics'] = [
                        [
                            'id' => '1',
                            'type' => 'count'
                        ]
                    ];
                    $q['timeField'] = '@timestamp';
                    $this->logger->debug("Added bucketAggs and metrics for ES time_series Lucene query: interval={$interval}");
                }
            } else {
                // Already JSON, assume it's DSL
                $q['query'] = $info['substituted_query'];
                // For time_series, if no aggs, add them
                $dsl = $parsedQuery;
                if ($q['format'] === 'time_series' && !isset($dsl['aggs'])) {
                    $interval = $this->calculateESInterval($stepMs);
                    $dsl["aggs"] = [
                        "time_agg" => [
                            "date_histogram" => [
                                "field" => "@timestamp",
                                "interval" => $interval,
                                "format" => "yyyy-MM-dd HH:mm:ss"
                            ]
                        ]
                    ];
                    $q['query'] = json_encode($dsl);
                    $this->logger->debug("Added aggs to DSL for ES time_series: {$q['query']}");
                }
            }
        } elseif ($dsType === 'grafana-clickhouse-datasource') {
            $q['rawSql'] = $info['substituted_query'];
            $q['queryType'] = 'sql';
            $q['format'] = 0; // Override format for ClickHouse
            $q['selectedFormat'] = 0;
            $q['meta'] = ['timezone' => 'Europe/Moscow'];
            $q['datasourceId'] = $info['datasource']['id'] ?? null;
        } else {
            $q['expr'] = $info['substituted_query'];
        }

        $queries = [$q];

        $body = json_encode([
            'from'    => (string)$fromMs,
            'to'      => (string)$toMs,
            'queries' => $queries,
        ]);
        $this->logger->info("DS query body for $metricName (type: {$this->lastDataSourceType}): " . substr($body, 0, 500));

        $resp = $this->httpClient->request('POST', "{$this->grafanaUrl}/api/ds/query", $body);
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
        // Метрики уже сохранены в initMetricsCache по частям
        $this->logger->info("Кэш метрик Grafana обновлен для instance {$this->instanceId}");
    }

    /**
     * Инициализируем $metricsCache из всех дашбордов Grafana (используется только в updateMetricsCache).
     */
    private function initMetricsCache(): void
    {
        // Сначала проверим кэш списка dashboards
        $dashboards = $this->cacheManager->loadGrafanaDashboardsList($this->instanceId);
        if ($dashboards === null) {
            // Кэш пустой, запрашиваем у Grafana
            $resp = $this->httpClient->request('GET', "{$this->grafanaUrl}/api/search?type=dash-db");
            if (!$resp) {
                $this->logger->error("Не удалось получить список дашбордов");
                return;
            }
            $dashboards = json_decode($resp, true);
            // Сохраняем в кэш
            if ($this->cacheManager->saveGrafanaDashboardsList($this->instanceId, $dashboards)) {
                $this->logger->info("Список dashboards сохранен в кэш для instance {$this->instanceId}");
            } else {
                $this->logger->warning("Не удалось сохранить список dashboards в кэш для instance {$this->instanceId}");
            }
        } else {
            $this->logger->info("Список dashboards загружен из кэша для instance {$this->instanceId}");
        }

        // Очистить старый кэш метрик
        $this->cacheManager->updateGrafanaIndividualMetrics($this->instanceId, []);

        $maxOptions = 50; // Можно взять из config
        $maxCombinations = 1000;

        foreach ($dashboards as $dash) {
            $uid   = $dash['uid'];
            $title = $dash['title'] ?: $uid;

            // Использование GrafanaVariableProcessor для получения комбинаций
            if (!$this->variableProcessor) {
                $this->logger->error("GrafanaVariableProcessor не установлен для instance {$this->instanceId}");
                continue;
            }
            $output = $this->variableProcessor->processVariables($this->grafanaUrl, $this->apiToken, $uid, $maxOptions, $maxCombinations);
            if (empty($output)) {
                $this->logger->warning("Не удалось обработать переменные для дашборда $uid");
                continue;
            }

            // Обработка вывода скрипта и сохранение метрик по частям
            foreach ($output as $panelId => $panelData) {
                // Пропустить 'variables', если есть
                if ($panelId === 'variables') {
                    continue;
                }
                $this->logger->debug("Processing panel {$panelId} title: '{$panelData['title']}', type: '{$panelData['type']}'");
                if (!isset($panelData['title'])) {
                    $this->logger->info("Пропущена панель {$panelId} в дашборде $uid: title не установлен");
                    continue;
                }
                // Пропустить панели, не являющиеся timeseries
                if (($panelData['type'] ?? '') !== 'timeseries') {
                    $this->logger->info("Пропущена панель {$panelId} в дашборде $uid: тип панели '{$panelData['type']}' не поддерживается");
                    continue;
                }
                $panelTitle = $panelData['title'];
                foreach ($panelData['queries'] as $queryData) {
                    $datasource = $queryData['datasource'];
                    // Check if the datasource is in the blacklist
                    $datasourceId = $datasource['uid'] ?? null;
                    if ($datasourceId && in_array($datasourceId, $this->blacklistDatasourceIds)) {
                        $this->logger->info("Пропущена панель {$panelId} в дашборде $uid: datasource $datasourceId в черном списке");
                        continue;
                    }
                    foreach ($queryData['combinations'] as $combData) {
                        $combination = $combData['combination'];
                        $substituted_query = $combData['substituted_query'];
                        $key = "{$title}, {$panelTitle}:" . json_encode($combination);
                        $metricData = [
                            'dashboard_uid' => $uid,
                            'panel_id'      => $panelId,
                            'dash_title'    => $title,
                            'panel_title'   => $panelTitle,
                            'type'          => $panelData['type'] ?? 'unknown',
                            'combination'   => $combination,
                            'substituted_query' => $substituted_query,
                            'datasource'    => $datasource,
                        ];
                        // Сохранить метрику сразу в БД
                        $this->cacheManager->saveGrafanaIndividualMetric($this->instanceId, $key, $metricData);
                        $this->logger->info("Метрика {$key} для instance {$this->instanceId} сохранена в БД");
                    }
                }
            }

            // Очистить память после обработки дашборда
            unset($output);
            $this->logger->info("Обработан дашборд $uid, метрики сохранены");
        }

        $this->logger->info("Кэш метрик Grafana инициализирован для instance {$this->instanceId}");
    }


    /**
     * In-request cache для JSON дашборда.
     */
    private function fetchDashboardData(string $uid): ?array
    {
        if (isset($this->dashCache[$uid])) {
            return $this->dashCache[$uid];
        }
        $dashJson = $this->httpClient->request('GET', "{$this->grafanaUrl}/api/dashboards/uid/{$uid}");
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
        $this->logger->debug("parseFrames data results keys: " . json_encode(array_keys($results)));
        foreach ($results as $frameSet) {
            if (!isset($frameSet['frames'])) {
                $this->logger->warning("No 'frames' key in frameSet for metric {$info['dash_title']}, {$info['panel_title']}: " . json_encode($frameSet));
                continue;
            }
            if (!is_array($frameSet['frames'])) {
                $this->logger->warning("'frames' is not array in frameSet for metric {$info['dash_title']}, {$info['panel_title']}: " . json_encode($frameSet['frames']));
                continue;
            }
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

        // Для danger dashboard только Prometheus с expr
        $dsType = $info['datasource']['type'] ?? '';
        if ($dsType !== 'prometheus') {
            $this->logger->info("Danger dashboard только для Prometheus, пропуск $metricName (type: $dsType)");
            return false;
        }

        $originalExpr = $info['substituted_query'];
        if (empty($originalExpr)) {
            $this->logger->warning("Substituted query не найден для $metricName");
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
        $resp = $this->httpClient->request('POST', "{$this->grafanaUrl}/api/dashboards/db", $body);
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

        // Используем substituted_query как нормализованный запрос
        $core = [
            'datasource' => $info['datasource'],
            'expr' => $info['substituted_query'],
        ];

        // Убираем volatile-поля
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

        $expr = $info['substituted_query'];
        $this->logger->info("Получен expr для $metricName: $expr");
        return $expr;
    }

    /**
     * Calculates Elasticsearch date_histogram interval from stepMs.
     */
    private function calculateESInterval(int $stepMs): string
    {
        $stepSec = $stepMs / 1000;
        if ($stepSec >= 86400) { // >= 1 day
            $days = intval($stepSec / 86400);
            return "{$days}d";
        } elseif ($stepSec >= 3600) { // >= 1 hour
            $hours = intval($stepSec / 3600);
            return "{$hours}h";
        } elseif ($stepSec >= 60) { // >= 1 min
            $mins = intval($stepSec / 60);
            return "{$mins}m";
        } else {
            $secs = intval($stepSec);
            return "{$secs}s";
        }
    }
}
?>