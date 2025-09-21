<?php
declare(strict_types=1);

namespace App\Cache;

use App\Utilities\Logger;
use App\Cache\SQLiteCacheDatabase;
use App\Cache\SQLiteCacheConfig;

class SQLiteCacheIO
{
    private $dbManager;
    private $logger;
    private $maxTtl;
    private $config;
    private $configManager;

    public function __construct(SQLiteCacheDatabase $dbManager, Logger $logger, int $maxTtl, array $config)
    {
        $this->dbManager = $dbManager;
        $this->logger = $logger;
        $this->maxTtl = $maxTtl;
        $this->config = $config;
        $this->configManager = new SQLiteCacheConfig($logger);
    }

    // L1 autoscale cache (no TTL cleanup)
    public function saveAutoscaleL1(string $query, string $labelsJson, array $info): bool
    {
        $db = $this->dbManager->getDb();
        try {
            $queryId = $this->configManager->getOrCreateQueryId($this->dbManager, $query, null, null);
            $metricHash = $this->generateCacheKey($query, $labelsJson);

            $requestMd5 = (string)($info['request_md5'] ?? '');
            $scaleCorridor = !empty($info['scale_corridor']) ? 1 : 0;
            $k = (int)($info['k'] ?? 8);
            $factor = isset($info['factor']) ? (float)$info['factor'] : null;

            $stmt = $db->prepare("
                INSERT OR REPLACE INTO autoscale_l1
                (query_id, metric_hash, request_md5, scale_corridor, k, factor, updated_at)
                VALUES (:query_id, :metric_hash, :request_md5, :scale_corridor, :k, :factor, CURRENT_TIMESTAMP)
            ");
            $ok = $stmt->execute([
                ':query_id' => $queryId,
                ':metric_hash' => $metricHash,
                ':request_md5' => $requestMd5,
                ':scale_corridor' => $scaleCorridor,
                ':k' => $k,
                ':factor' => $factor
            ]);
            if ($ok) {
                $this->logger->info("L1 autoscale saved: query_id={$queryId}, metric_hash={$metricHash}, scale={$scaleCorridor}, k={$k}, factor=" . ($factor ?? 'null'));
            }
            return (bool)$ok;
        } catch (\PDOException $e) {
            $this->logger->error("Не удалось сохранить L1 autoscale: " . $e->getMessage());
            return false;
        }
    }

    public function loadAutoscaleL1(string $query, string $labelsJson): ?array
    {
        $db = $this->dbManager->getDb();
        try {
            // resolve query_id; if no row in queries, nothing to return
            $stmtQ = $db->prepare("SELECT id FROM queries WHERE query = :query");
            $stmtQ->execute([':query' => $query]);
            $qid = $stmtQ->fetchColumn();
            if (!$qid) {
                return null;
            }

            $metricHash = $this->generateCacheKey($query, $labelsJson);
            $stmt = $db->prepare("
                SELECT request_md5, scale_corridor, k, factor, updated_at
                FROM autoscale_l1
                WHERE query_id = :query_id AND metric_hash = :metric_hash
                LIMIT 1
            ");
            $stmt->execute([
                ':query_id' => $qid,
                ':metric_hash' => $metricHash
            ]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            return [
                'request_md5' => (string)$row['request_md5'],
                'scale_corridor' => (int)$row['scale_corridor'] === 1,
                'k' => (int)$row['k'],
                'factor' => isset($row['factor']) ? (float)$row['factor'] : null,
                'updated_at' => $row['updated_at']
            ];
        } catch (\PDOException $e) {
            $this->logger->error("Не удалось загрузить L1 autoscale: " . $e->getMessage());
            return null;
        }
    }

    // Permanent metrics cache
    public function saveMetricsCacheL1(string $query, string $labelsJson, array $info): bool
    {
        $db = $this->dbManager->getDb();
        try {
            $queryId = $this->configManager->getOrCreateQueryId($this->dbManager, $query, null, null);
            $metricHash = $this->generateCacheKey($query, $labelsJson);

            $requestMd5 = (string)($info['request_md5'] ?? '');
            $optimalPeriodDays = isset($info['optimal_period_days']) ? (float)$info['optimal_period_days'] : null;
            $scaleCorridor = !empty($info['scale_corridor']) ? 1 : 0;
            $k = (int)($info['k'] ?? 8);
            $factor = isset($info['factor']) ? (float)$info['factor'] : null;

            $stmt = $db->prepare("
                INSERT OR REPLACE INTO metrics_cache_permanent
                (query_id, metric_hash, request_md5, optimal_period_days, scale_corridor, k, factor, updated_at)
                VALUES (:query_id, :metric_hash, :request_md5, :optimal_period_days, :scale_corridor, :k, :factor, CURRENT_TIMESTAMP)
            ");
            $ok = $stmt->execute([
                ':query_id' => $queryId,
                ':metric_hash' => $metricHash,
                ':request_md5' => $requestMd5,
                ':optimal_period_days' => $optimalPeriodDays,
                ':scale_corridor' => $scaleCorridor,
                ':k' => $k,
                ':factor' => $factor
            ]);
            if ($ok) {
                $this->logger->info("L1 metrics saved: query_id={$queryId}, metric_hash={$metricHash}, md5={$requestMd5}, period={$optimalPeriodDays}, scale={$scaleCorridor}, k={$k}, factor=" . ($factor ?? 'null'));
            }
            return (bool)$ok;
        } catch (\PDOException $e) {
            $this->logger->error("Не удалось сохранить L1 metrics: " . $e->getMessage());
            return false;
        }
    }

    public function loadMetricsCacheL1(string $query, string $labelsJson): ?array
    {
        $db = $this->dbManager->getDb();
        try {
            // resolve query_id; if no row in queries, nothing to return
            $stmtQ = $db->prepare("SELECT id FROM queries WHERE query = :query");
            $stmtQ->execute([':query' => $query]);
            $qid = $stmtQ->fetchColumn();
            if (!$qid) {
                return null;
            }

            $metricHash = $this->generateCacheKey($query, $labelsJson);
            $stmt = $db->prepare("
                SELECT request_md5, optimal_period_days, scale_corridor, k, factor, updated_at
                FROM metrics_cache_permanent
                WHERE query_id = :query_id AND metric_hash = :metric_hash
                LIMIT 1
            ");
            $stmt->execute([
                ':query_id' => $qid,
                ':metric_hash' => $metricHash
            ]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            return [
                'request_md5' => (string)$row['request_md5'],
                'optimal_period_days' => isset($row['optimal_period_days']) ? (float)$row['optimal_period_days'] : null,
                'scale_corridor' => (int)$row['scale_corridor'] === 1,
                'k' => (int)$row['k'],
                'factor' => isset($row['factor']) ? (float)$row['factor'] : null,
                'updated_at' => $row['updated_at']
            ];
        } catch (\PDOException $e) {
            $this->logger->error("Не удалось загрузить L1 metrics: " . $e->getMessage());
            return null;
        }
    }

    public function generateCacheKey(string $query, string $labelsJson): string
    {
        // Канонизация: deep sort labelsJson если это массив
        $labels = json_decode($labelsJson, true);
        if (is_array($labels)) {
            $this->deepKsort($labels);
            $normalizedLabelsJson = json_encode($labels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $normalizedLabelsJson = $labelsJson;
        }
        return md5($query . $normalizedLabelsJson);
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

    public function saveToCache(string $query, string $labelsJson, array $data, array $currentConfig): bool
    {
        $db = $this->dbManager->getDb();
        try {
            if (!$db->inTransaction()) {
                $db->beginTransaction();
            }
            $currentConfigHash = $this->configManager->createConfigHash($currentConfig);
            $queryId = $this->configManager->getOrCreateQueryId($this->dbManager, $query, null, $currentConfigHash);
            $metricHash = $this->generateCacheKey($query, $labelsJson);
            $meta = $data['meta'] ?? [];
            $stmt = $db->prepare(
                "INSERT OR REPLACE INTO dft_cache
                (query_id, metric_hash, metric_json, data_start, step, total_duration,
                 dft_rebuild_count, labels_json, created_at,
                 anomaly_stats_json, dft_upper_json, dft_lower_json,
                 upper_trend_json, lower_trend_json, last_accessed)
                VALUES (:query_id, :metric_hash, :metric_json, :data_start, :step,
                        :total_duration, :dft_rebuild_count, :labels_json,
                        :created_at, :anomaly_stats_json, :dft_upper_json, :dft_lower_json,
                        :upper_trend_json, :lower_trend_json, CURRENT_TIMESTAMP)"
            );
            $stmt->execute([
                ':query_id' => $queryId,
                ':metric_hash' => $metricHash,
                ':metric_json' => $labelsJson,
                ':data_start' => $meta['dataStart'] ?? null,
                ':step' => $meta['step'] ?? null,
                ':total_duration' => $meta['totalDuration'] ?? null,
                ':dft_rebuild_count' => $meta['dft_rebuild_count'] ?? 0,
                ':labels_json' => json_encode($meta['labels'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':created_at' => $meta['created_at'] ?? time(),
                ':anomaly_stats_json' => json_encode($meta['anomaly_stats'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':dft_upper_json' => json_encode($data['dft_upper']['coefficients'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':dft_lower_json' => json_encode($data['dft_lower']['coefficients'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':upper_trend_json' => json_encode($data['dft_upper']['trend'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':lower_trend_json' => json_encode($data['dft_lower']['trend'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ]);
            if ($db->inTransaction()) {
                $db->commit();
            }
            $this->logger->info("Сохранен кэш для запроса: $query, dft_rebuild_count: {$meta['dft_rebuild_count']}, config_hash: $currentConfigHash");
            return true;
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->logger->error("Не удалось сохранить в кэш SQLite: " . $e->getMessage());
            return false;
        }
    }

    public function loadFromCache(string $query, string $labelsJson): ?array
    {
        $db = $this->dbManager->getDb();
        try {
            $metricHash = $this->generateCacheKey($query, $labelsJson);
            $stmt = $db->prepare(
                "SELECT
                    q.id, q.config_hash, dc.data_start, dc.step, dc.total_duration,
                    dc.dft_rebuild_count, dc.labels_json, dc.created_at,
                    dc.anomaly_stats_json, dc.dft_upper_json, dc.dft_lower_json,
                    dc.upper_trend_json, dc.lower_trend_json, dc.last_accessed
                FROM queries q
                JOIN dft_cache dc ON q.id = dc.query_id
                WHERE q.query = :query AND dc.metric_hash = :metric_hash"
            );
            $stmt->execute([':query' => $query, ':metric_hash' => $metricHash]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $this->updateLastAccessedIfNeeded($db, $row['id'], $metricHash, $row['last_accessed']);
            return [
                'meta' => [
                    'dataStart' => $row['data_start'],
                    'step' => $row['step'],
                    'totalDuration' => $row['total_duration'],
                    'config_hash' => $row['config_hash'],
                    'dft_rebuild_count' => $row['dft_rebuild_count'],
                    'query' => $query,
                    'labels' => json_decode($row['labels_json'], true) ?? [],
                    'created_at' => $row['created_at'],
                    'anomaly_stats' => json_decode($row['anomaly_stats_json'], true) ?? []
                ],
                'dft_upper' => [
                    'coefficients' => json_decode($row['dft_upper_json'], true) ?? [],
                    'trend' => json_decode($row['upper_trend_json'], true) ?? ['slope' => 0, 'intercept' => 0]
                ],
                'dft_lower' => [
                    'coefficients' => json_decode($row['dft_lower_json'], true) ?? [],
                    'trend' => json_decode($row['lower_trend_json'], true) ?? ['slope' => 0, 'intercept' => 0]
                ]
            ];
        } catch (PDOException $e) {
            $this->logger->error("Не удалось загрузить из кэша SQLite: " . $e->getMessage());
            return null;
        }
    }

    public function getAllCachedMetrics(string $query): array
    {
        $db = $this->dbManager->getDb();
        try {
            $stmt = $db->prepare(
                "SELECT
                    q.id, q.config_hash, dc.metric_json, dc.data_start, dc.step, dc.total_duration,
                    dc.dft_rebuild_count, dc.labels_json, dc.created_at,
                    dc.anomaly_stats_json, dc.dft_upper_json, dc.dft_lower_json,
                    dc.upper_trend_json, dc.lower_trend_json, dc.last_accessed
                FROM queries q
                JOIN dft_cache dc ON q.id = dc.query_id
                WHERE q.query = :query"
            );
            $stmt->execute([':query' => $query]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $results = [];
            foreach ($rows as $row) {
                $labelsJson = $row['metric_json'];
                $this->updateLastAccessedIfNeeded($db, $row['id'], $this->generateCacheKey($query, $labelsJson), $row['last_accessed']);
                $results[$labelsJson] = [
                    'meta' => [
                        'dataStart' => $row['data_start'],
                        'step' => $row['step'],
                        'totalDuration' => $row['total_duration'],
                        'config_hash' => $row['config_hash'],
                        'dft_rebuild_count' => $row['dft_rebuild_count'],
                        'query' => $query,
                        'labels' => json_decode($row['labels_json'], true) ?? [],
                        'created_at' => $row['created_at'],
                        'anomaly_stats' => json_decode($row['anomaly_stats_json'], true) ?? []
                    ],
                    'dft_upper' => [
                        'coefficients' => json_decode($row['dft_upper_json'], true) ?? [],
                        'trend' => json_decode($row['upper_trend_json'], true) ?? ['slope' => 0, 'intercept' => 0]
                    ],
                    'dft_lower' => [
                        'coefficients' => json_decode($row['dft_lower_json'], true) ?? [],
                        'trend' => json_decode($row['lower_trend_json'], true) ?? ['slope' => 0, 'intercept' => 0]
                    ]
                ];
            }
            return $results;
        } catch (PDOException $e) {
            $this->logger->error("Не удалось загрузить все кэшированные метрики: " . $e->getMessage());
            return [];
        }
    }

    public function checkCacheExists(string $query, string $labelsJson): bool
    {
        $db = $this->dbManager->getDb();
        try {
            $metricHash = $this->generateCacheKey($query, $labelsJson);
            $stmt = $db->prepare(
                "SELECT COUNT(*)
                FROM queries q
                JOIN dft_cache dc ON q.id = dc.query_id
                WHERE q.query = :query AND dc.metric_hash = :metric_hash"
            );
            $stmt->execute([':query' => $query, ':metric_hash' => $metricHash]);
            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            $this->logger->error("Не удалось проверить наличие кэша SQLite: " . $e->getMessage());
            return false;
        }
    }

    public function shouldRecreateCache(string $query, string $labelsJson, array $currentConfig): bool
    {
        $db = $this->dbManager->getDb();
        try {
            $metricHash = $this->generateCacheKey($query, $labelsJson);
            $stmt = $db->prepare(
                "SELECT q.config_hash, dc.created_at, dc.labels_json, dc.anomaly_stats_json, dc.dft_rebuild_count
                FROM queries q
                JOIN dft_cache dc ON q.id = dc.query_id
                WHERE q.query = :query AND dc.metric_hash = :metric_hash"
            );
            $stmt->execute([':query' => $query, ':metric_hash' => $metricHash]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row || !isset($row['config_hash'])) {
                return true;
            }
            $labels = json_decode($row['labels_json'], true);
            if (isset($labels['unused_metric']) && $labels['unused_metric'] === 'true') {
                $age = time() - $row['created_at'];
                if ($age <= $this->maxTtl) {
                    return false;
                }
            }
            $currentConfigHash = $this->configManager->createConfigHash($currentConfig);
            if ($row['config_hash'] !== $currentConfigHash) {
                $this->logger->info("Конфигурация изменилась для запроса: $query. Текущий хеш: $currentConfigHash, сохраненный: {$row['config_hash']}. Требуется пересоздание кэша.");
                return true;
            }
            $age = time() - $row['created_at'];
            if ($age > $this->maxTtl) {
                $this->logger->info("Кэш устарел для запроса: $query. Возраст: $age секунд");
                return true;
            }
            if ($row['dft_rebuild_count'] > ($this->config['cache']['max_rebuild_count'] ?? 10)) {
                $this->logger->warning("Высокое значение dft_rebuild_count ({$row['dft_rebuild_count']}) для запроса: $query, метрика: $labelsJson. Возможен конфликт конфигураций.");
            }
            return false;
        } catch (PDOException $e) {
            $this->logger->error("Не удалось проверить необходимость пересоздания кэша SQLite: " . $e->getMessage());
            return true;
        }
    }

    private function updateLastAccessedIfNeeded(\PDO $db, int $queryId, string $metricHash, string $lastAccessed): void
    {
        $currentHour = date('Y-m-d H:00:00');
        $lastAccessedHour = date('Y-m-d H:00:00', strtotime($lastAccessed));
        if ($currentHour !== $lastAccessedHour) {
            $db->prepare(
                "UPDATE queries SET last_accessed = CURRENT_TIMESTAMP WHERE id = :query_id"
            )->execute([':query_id' => $queryId]);
            $db->prepare(
                "UPDATE dft_cache SET last_accessed = CURRENT_TIMESTAMP
                WHERE query_id = :query_id AND metric_hash = :metric_hash"
            )->execute([':query_id' => $queryId, ':metric_hash' => $metricHash]);
        }
    }

    public function saveGrafanaMetrics(array $metrics): bool
    {
        $db = $this->dbManager->getDb();
        try {
            foreach ($metrics as $query => $info) {
                $payload = json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $stmt = $db->prepare("INSERT OR REPLACE INTO grafana_metrics (query, payload_json, updated_at) VALUES (:query, :payload, CURRENT_TIMESTAMP)");
                $stmt->execute([':query' => $query, ':payload' => $payload]);
            }
            $this->logger->info("Grafana metrics saved successfully");
            return true;
        } catch (\PDOException $e) {
            $this->logger->error("Failed to save Grafana metrics: " . $e->getMessage());
            return false;
        }
    }

    public function loadGrafanaMetrics(): array
    {
        $db = $this->dbManager->getDb();
        try {
            $stmt = $db->prepare("SELECT query, payload_json FROM grafana_metrics");
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $result = [];
            foreach ($rows as $row) {
                $result[$row['query']] = json_decode($row['payload_json'], true);
            }
            return $result;
        } catch (\PDOException $e) {
            $this->logger->error("Failed to load Grafana metrics: " . $e->getMessage());
            return [];
        }
    }

    public function saveMaxPeriod(string $metricKey, float $maxPeriodDays): bool
    {
        $db = $this->dbManager->getDb();
        try {
            $stmt = $db->prepare("INSERT OR REPLACE INTO metrics_max_periods (metric_key, max_period_days, updated_at) VALUES (:key, :period, CURRENT_TIMESTAMP)");
            $stmt->execute([':key' => $metricKey, ':period' => $maxPeriodDays]);
            $this->logger->info("Max period saved for metric: $metricKey");
            return true;
        } catch (\PDOException $e) {
            $this->logger->error("Failed to save max period: " . $e->getMessage());
            return false;
        }
    }

    public function loadMaxPeriod(string $metricKey): ?float
    {
        $db = $this->dbManager->getDb();
        try {
            $stmt = $db->prepare("SELECT max_period_days FROM metrics_max_periods WHERE metric_key = :key LIMIT 1");
            $stmt->execute([':key' => $metricKey]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ? (float)$row['max_period_days'] : null;
        } catch (\PDOException $e) {
            $this->logger->error("Failed to load max period: " . $e->getMessage());
            return null;
        }
    }
}
?>