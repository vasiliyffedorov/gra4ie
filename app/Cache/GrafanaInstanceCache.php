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
            $this->logger->info("Saving blacklist_uids to DB: " . $blacklistUids);
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
            $this->logger->info("Экземпляр Grafana сохранен в кэше: " . $url . ", blacklist_uids: " . $blacklistUids);
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
                    $this->logger->info("Loaded blacklist_uids from DB for instance {$row['id']}: " . json_encode($blacklistUids));
                    $instances[] = [
                        'id' => (int)$row['id'],
                        'name' => $row['name'],
                        'url' => $row['url'],
                        'token' => $row['token'],
                        'blacklist_uids' => $blacklistUids,
                        'created_at' => $row['created_at']
                    ];
                } else {
                    $this->logger->error("Failed to decode blacklist_uids for instance {$row['id']}: " . $row['blacklist_uids']);
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

    public function getInstanceByUrl(string $url): ?array
    {
        $db = $this->dbManager->getDb();
        try {
            $stmt = $db->prepare("SELECT id, name, url, token, blacklist_uids, created_at FROM grafana_instances WHERE url = :url LIMIT 1");
            $stmt->execute([':url' => $url]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $blacklistUids = json_decode($row['blacklist_uids'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->logger->info("Loaded blacklist_uids from DB for URL {$url}: " . json_encode($blacklistUids));
                    return [
                        'id' => (int)$row['id'],
                        'name' => $row['name'],
                        'url' => $row['url'],
                        'token' => $row['token'],
                        'blacklist_uids' => $blacklistUids,
                        'created_at' => $row['created_at']
                    ];
                } else {
                    $this->logger->error("Failed to decode blacklist_uids for URL {$url}: " . $row['blacklist_uids']);
                }
            }
            return null;
        } catch (PDOException $e) {
            $this->logger->error("Ошибка загрузки экземпляра Grafana по URL: " . $e->getMessage());
            return null;
        }
    }

    public function getInstanceById(int $id): ?array
    {
        $db = $this->dbManager->getDb();
        try {
            $stmt = $db->prepare("SELECT id, name, url, token, blacklist_uids, created_at FROM grafana_instances WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $blacklistUids = json_decode($row['blacklist_uids'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->logger->info("Loaded blacklist_uids from DB for ID {$id}: " . json_encode($blacklistUids));
                    return [
                        'id' => (int)$row['id'],
                        'name' => $row['name'],
                        'url' => $row['url'],
                        'token' => $row['token'],
                        'blacklist_uids' => $blacklistUids,
                        'created_at' => $row['created_at']
                    ];
                } else {
                    $this->logger->error("Failed to decode blacklist_uids for ID {$id}: " . $row['blacklist_uids']);
                }
            }
            return null;
        } catch (\PDOException $e) {
            $this->logger->error("Ошибка загрузки экземпляра Grafana по ID: " . $e->getMessage());
            return null;
        }
    }
}