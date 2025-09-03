<?php
require_once __DIR__ . '/../Utilities/Logger.php';
require_once __DIR__ . '/SQLiteCacheDatabase.php';

class SQLiteCacheConfig
{
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function getOrCreateQueryId(SQLiteCacheDatabase $dbManager, string $query, ?string $customParams = null, ?string $configHash = null): int
    {
        $db = $dbManager->getDb();
        try {
            $inTransaction = $db->inTransaction();
            if (!$inTransaction) {
                $db->beginTransaction();
            }
            $stmt = $db->prepare("SELECT id, last_accessed, custom_params, config_hash FROM queries WHERE query = :query");
            $stmt->execute([':query' => $query]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $this->updateLastAccessedQueryIfNeeded($db, $row['id'], $row['last_accessed']);
                if ($customParams !== null || $configHash !== null) {
                    $this->updateQueryParams($db, $row['id'], $customParams ?? $row['custom_params'], $configHash ?? $row['config_hash']);
                }
                if (!$inTransaction) {
                    $db->commit();
                }
                return $row['id'];
            }
            $stmt = $db->prepare("INSERT INTO queries (query, custom_params, config_hash) VALUES (:query, :custom_params, :config_hash)");
            $stmt->execute([
                ':query' => $query,
                ':custom_params' => $customParams,
                ':config_hash' => $configHash
            ]);
            $queryId = $db->lastInsertId();
            if (!$inTransaction) {
                $db->commit();
            }
            $this->logger->info("Создана новая запись в queries для запроса: $query, query_id: $queryId", __FILE__, __LINE__);
            return $queryId;
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->logger->error("Ошибка при получении или создании query_id для запроса: $query, ошибка: " . $e->getMessage(), __FILE__, __LINE__);
            throw new Exception("Не удалось получить или создать query_id");
        }
    }

    public function getCustomParams(SQLiteCacheDatabase $dbManager, string $query): ?string
    {
        $db = $dbManager->getDb();
        try {
            $stmt = $db->prepare("SELECT custom_params FROM queries WHERE query = :query");
            $stmt->execute([':query' => $query]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row['custom_params'] ?? null;
        } catch (PDOException $e) {
            $this->logger->error("Не удалось получить кастомные параметры для запроса: $query, ошибка: " . $e->getMessage(), __FILE__, __LINE__);
            return null;
        }
    }

    public function resetCustomParams(SQLiteCacheDatabase $dbManager, string $query): bool
    {
        $db = $dbManager->getDb();
        try {
            $inTransaction = $db->inTransaction();
            if (!$inTransaction) {
                $db->beginTransaction();
            }
            $stmt = $db->prepare("UPDATE queries SET custom_params = NULL, config_hash = NULL WHERE query = :query");
            $stmt->execute([':query' => $query]);
            if (!$inTransaction) {
                $db->commit();
            }
            $this->logger->info("Сброшены кастомные параметры и config_hash для запроса: $query", __FILE__, __LINE__);
            return true;
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->logger->error("Не удалось сбросить кастомные параметры для запроса: $query, ошибка: " . $e->getMessage(), __FILE__, __LINE__);
            return false;
        }
    }

    private function updateQueryParams(PDO $db, int $queryId, ?string $customParams, ?string $configHash): void
    {
        try {
            $inTransaction = $db->inTransaction();
            if (!$inTransaction) {
                $db->beginTransaction();
            }
            $stmt = $db->prepare("UPDATE queries SET custom_params = :custom_params, config_hash = :config_hash, last_accessed = CURRENT_TIMESTAMP WHERE id = :query_id");
            $stmt->execute([
                ':custom_params' => $customParams,
                ':config_hash' => $configHash,
                ':query_id' => $queryId
            ]);
            if (!$inTransaction) {
                $db->commit();
            }
            $this->logger->info("Обновлены параметры для query_id: $queryId, custom_params: $customParams, config_hash: $configHash", __FILE__, __LINE__);
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->logger->error("Не удалось обновить параметры для query_id: $queryId, ошибка: " . $e->getMessage(), __FILE__, __LINE__);
            throw new Exception("Не удалось обновить параметры запроса");
        }
    }

    private function updateLastAccessedQueryIfNeeded(PDO $db, int $queryId, string $lastAccessed): void
    {
        $currentHour = date('Y-m-d H:00:00');
        $lastAccessedHour = date('Y-m-d H:00:00', strtotime($lastAccessed));
        if ($currentHour !== $lastAccessedHour) {
            $db->prepare(
                "UPDATE queries SET last_accessed = CURRENT_TIMESTAMP WHERE id = :query_id"
            )->execute([':query_id' => $queryId]);
        }
    }

    public function createConfigHash(array $config): string
    {
        $standardizedConfig = json_decode(json_encode($config), true);
        array_walk_recursive($standardizedConfig, function (&$value) {
            if (is_float($value)) {
                $value = round($value, 5);
            }
        });
        ksort($standardizedConfig);
        $filteredConfig = array_filter($standardizedConfig, function ($key) {
            return !str_starts_with($key, 'save');
        }, ARRAY_FILTER_USE_KEY);
        return md5(json_encode($filteredConfig));
    }
}
?>