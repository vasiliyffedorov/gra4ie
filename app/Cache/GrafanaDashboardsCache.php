<?php
declare(strict_types=1);

namespace App\Cache;

use App\Interfaces\LoggerInterface;
use PDOException;

class GrafanaDashboardsCache
{
    private SQLiteCacheDatabase $dbManager;
    private LoggerInterface $logger;

    public function __construct(SQLiteCacheDatabase $dbManager, LoggerInterface $logger)
    {
        $this->dbManager = $dbManager;
        $this->logger = $logger;
    }

    public function saveDashboardsList(int $instanceId, array $dashboards): bool
    {
        $db = $this->dbManager->getDb();
        try {
            $dashboardsJson = json_encode($dashboards, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $stmt = $db->prepare("
                INSERT OR REPLACE INTO grafana_dashboards_cache (instance_id, dashboards_json, last_updated)
                VALUES (:instance_id, :dashboards_json, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                ':instance_id' => $instanceId,
                ':dashboards_json' => $dashboardsJson
            ]);
            $this->logger->info("Список dashboards для instance $instanceId сохранен в БД");
            return true;
        } catch (PDOException $e) {
            $this->logger->error("Ошибка сохранения списка dashboards для instance $instanceId: " . $e->getMessage());
            return false;
        }
    }

    public function loadDashboardsList(int $instanceId): ?array
    {
        $db = $this->dbManager->getDb();
        try {
            $stmt = $db->prepare("SELECT dashboards_json FROM grafana_dashboards_cache WHERE instance_id = :instance_id ORDER BY last_updated DESC LIMIT 1");
            $stmt->execute([':instance_id' => $instanceId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $dashboards = json_decode($row['dashboards_json'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->logger->info("Список dashboards для instance $instanceId загружен из БД");
                    return $dashboards;
                }
            }
            return null;
        } catch (PDOException $e) {
            $this->logger->error("Ошибка загрузки списка dashboards для instance $instanceId: " . $e->getMessage());
            return null;
        }
    }
}