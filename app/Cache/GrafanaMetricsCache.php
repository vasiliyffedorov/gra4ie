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

    public function saveMetrics(array $metrics): bool
    {
        $db = $this->dbManager->getDb();
        try {
            $json = json_encode($metrics, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $stmt = $db->prepare("
                INSERT INTO grafana_metrics (metrics_key, metrics_json, last_updated)
                VALUES ('global_metrics', :json, CURRENT_TIMESTAMP)
                ON CONFLICT(metrics_key) DO UPDATE SET
                    metrics_json = :json,
                    last_updated = CURRENT_TIMESTAMP
            ");
            $stmt->execute([':json' => $json]);
            $this->logger->info("Кэш метрик Grafana сохранен в БД");
            return true;
        } catch (PDOException $e) {
            $this->logger->error("Ошибка сохранения кэша метрик Grafana: " . $e->getMessage());
            return false;
        }
    }

    public function loadMetrics(): ?array
    {
        $db = $this->dbManager->getDb();
        try {
            $stmt = $db->prepare("SELECT metrics_json FROM grafana_metrics WHERE metrics_key = 'global_metrics'");
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $metrics = json_decode($row['metrics_json'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->logger->info("Кэш метрик Grafana загружен из БД");
                    return $metrics;
                }
            }
            return null;
        } catch (PDOException $e) {
            $this->logger->error("Ошибка загрузки кэша метрик Grafana: " . $e->getMessage());
            return null;
        }
    }
}