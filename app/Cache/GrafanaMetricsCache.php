<?php
declare(strict_types=1);

namespace App\Cache;

use App\Interfaces\LoggerInterface;
use PDOException;

class GrafanaMetricsCache
{
    private SQLiteCacheDatabase $dbManager;
    private LoggerInterface $logger;

    public function __construct(SQLiteCacheDatabase $dbManager, LoggerInterface $logger)
    {
        $this->dbManager = $dbManager;
        $this->logger = $logger;
    }

    public function saveMetric(int $instanceId, string $metricKey, array $metricData): bool
    {
        $db = $this->dbManager->getDb();
        try {
            $metricJson = json_encode($metricData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $stmt = $db->prepare("
                INSERT OR REPLACE INTO grafana_individual_metrics (instance_id, metric_key, metric_json, last_updated)
                VALUES (:instance_id, :metric_key, :metric_json, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                ':instance_id' => $instanceId,
                ':metric_key' => $metricKey,
                ':metric_json' => $metricJson
            ]);
            $this->logger->info("Метрика $metricKey для instance $instanceId сохранена в БД");
            return true;
        } catch (PDOException $e) {
            $this->logger->error("Ошибка сохранения метрики $metricKey для instance $instanceId: " . $e->getMessage());
            return false;
        }
    }

    public function loadMetrics(int $instanceId): array
    {
        $db = $this->dbManager->getDb();
        try {
            $stmt = $db->prepare("SELECT instance_id, metric_key, metric_json FROM grafana_individual_metrics WHERE instance_id = :instance_id ORDER BY last_updated DESC");
            $stmt->execute([':instance_id' => $instanceId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $metrics = [];
            foreach ($rows as $row) {
                $metricData = json_decode($row['metric_json'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $metrics[] = [
                        'instance_id' => (int)$row['instance_id'],
                        'metric_key' => $row['metric_key'],
                        'metric_data' => $metricData
                    ];
                }
            }
            $this->logger->info("Кэш метрик для instance $instanceId загружен из БД");
            return $metrics;
        } catch (PDOException $e) {
            $this->logger->error("Ошибка загрузки кэша метрик для instance $instanceId: " . $e->getMessage());
            return [];
        }
    }

    public function updateMetricsCache(int $instanceId, array $metrics): bool
    {
        $db = $this->dbManager->getDb();
        try {
            $db->beginTransaction();
            // Delete old metrics for this instance
            $stmt = $db->prepare("DELETE FROM grafana_individual_metrics WHERE instance_id = :instance_id");
            $stmt->execute([':instance_id' => $instanceId]);
            // Insert new metrics
            foreach ($metrics as $metric) {
                $metricKey = $metric['metric_key'];
                $metricJson = json_encode($metric['metric_data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $stmt = $db->prepare("
                    INSERT INTO grafana_individual_metrics (instance_id, metric_key, metric_json, last_updated)
                    VALUES (:instance_id, :metric_key, :metric_json, CURRENT_TIMESTAMP)
                ");
                $stmt->execute([
                    ':instance_id' => $instanceId,
                    ':metric_key' => $metricKey,
                    ':metric_json' => $metricJson
                ]);
            }
            $db->commit();
            $this->logger->info("Кэш метрик для instance $instanceId обновлен в БД");
            return true;
        } catch (PDOException $e) {
            $db->rollBack();
            $this->logger->error("Ошибка обновления кэша метрик для instance $instanceId: " . $e->getMessage());
            return false;
        }
    }
}