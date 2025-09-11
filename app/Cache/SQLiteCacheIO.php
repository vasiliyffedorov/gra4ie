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

    public function generateCacheKey(string $query, string $labelsJson): string
    {
        return md5($query . $labelsJson);
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
                $this->logger->legacyInfo("Конфигурация изменилась для запроса: $query. Текущий хеш: $currentConfigHash, сохраненный: {$row['config_hash']}. Требуется пересоздание кэша.", __FILE__, __LINE__);
                return true;
            }
            $age = time() - $row['created_at'];
            if ($age > $this->maxTtl) {
                $this->logger->info("Кэш устарел для запроса: $query. Возраст: $age секунд");
                return true;
            }
            if ($row['dft_rebuild_count'] > ($this->config['cache']['max_rebuild_count'] ?? 10)) {
                $this->logger->legacyWarn("Высокое значение dft_rebuild_count ({$row['dft_rebuild_count']}) для запроса: $query, метрика: $labelsJson. Возможен конфликт конфигураций.", __FILE__, __LINE__);
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
}
?>