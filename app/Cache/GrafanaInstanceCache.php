<?php
declare(strict_types=1);

namespace App\Cache;

use App\Interfaces\LoggerInterface;
use PDOException;

class GrafanaInstanceCache
{
    private SQLiteCacheDatabase $dbManager;
    private LoggerInterface $logger;

    public function __construct(SQLiteCacheDatabase $dbManager, LoggerInterface $logger)
    {
        $this->dbManager = $dbManager;
        $this->logger = $logger;
    }

    public function saveInstance(array $instance): bool
    {
        $db = $this->dbManager->getDb();
        try {
            $db->beginTransaction();
            $name = $instance['name'];
            $url = $instance['url'];
            $token = $instance['token'];
            $blacklistUids = json_encode($instance['blacklist_uids'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $stmt = $db->prepare("
                INSERT OR REPLACE INTO grafana_instances (name, url, token, blacklist_uids)
                VALUES (:name, :url, :token, :blacklist_uids)
            ");
            $stmt->execute([
                ':name' => $name,
                ':url' => $url,
                ':token' => $token,
                ':blacklist_uids' => $blacklistUids
            ]);
            $db->commit();
            $this->logger->info("Экземпляр Grafana сохранен в кэше: " . $url);
            return true;
        } catch (PDOException $e) {
            $db->rollBack();
            $this->logger->error("Ошибка сохранения экземпляра Grafana: " . $e->getMessage());
            return false;
        }
    }

    public function loadInstances(): array
    {
        $db = $this->dbManager->getDb();
        try {
            $stmt = $db->prepare("SELECT id, name, url, token, blacklist_uids, created_at FROM grafana_instances ORDER BY created_at DESC");
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $instances = [];
            foreach ($rows as $row) {
                $blacklistUids = json_decode($row['blacklist_uids'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $instances[] = [
                        'id' => (int)$row['id'],
                        'name' => $row['name'],
                        'url' => $row['url'],
                        'token' => $row['token'],
                        'blacklist_uids' => $blacklistUids,
                        'created_at' => $row['created_at']
                    ];
                }
            }
            $this->logger->info("Экземпляры Grafana загружены из кэша");
            return $instances;
        } catch (PDOException $e) {
            $this->logger->error("Ошибка загрузки экземпляров Grafana: " . $e->getMessage());
            return [];
        }
    }

    public function instanceExistsByUrl(string $url): bool
    {
        $db = $this->dbManager->getDb();
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM grafana_instances WHERE url = :url");
            $stmt->execute([':url' => $url]);
            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            $this->logger->error("Ошибка проверки существования экземпляра по URL: " . $e->getMessage());
            return false;
        }
    }

    public function instanceExistsById(int $id): bool
    {
        $db = $this->dbManager->getDb();
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM grafana_instances WHERE id = :id");
            $stmt->execute([':id' => $id]);
            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            $this->logger->error("Ошибка проверки существования экземпляра по ID: " . $e->getMessage());
            return false;
        }
    }
}