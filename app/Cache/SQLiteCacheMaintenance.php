<?php
require_once __DIR__ . '/../Utilities/Logger.php';
require_once __DIR__ . '/SQLiteCacheDatabase.php';

class SQLiteCacheMaintenance
{
    private $dbManager;
    private $logger;

    public function __construct(SQLiteCacheDatabase $dbManager, Logger $logger)
    {
        $this->dbManager = $dbManager;
        $this->logger = $logger;
    }

    public function cleanupOldEntries(int $maxAgeDays = 30): void
    {
        $db = $this->dbManager->getDb();
        try {
            $cutoff = date('Y-m-d H:i:s', strtotime("-$maxAgeDays days"));
            $db->exec("DELETE FROM dft_cache WHERE last_accessed < '$cutoff'");
            $db->exec(
                "DELETE FROM queries
                WHERE id NOT IN (SELECT DISTINCT query_id FROM dft_cache)
                AND last_accessed < '$cutoff'"
            );
            $this->logger->info("Очищены старые записи кэша старше $maxAgeDays дней", __FILE__, __LINE__);
        } catch (PDOException $e) {
            $this->logger->error("Не удалось очистить старые записи кэша SQLite: " . $e->getMessage(), __FILE__, __LINE__);
        }
    }
}
?>