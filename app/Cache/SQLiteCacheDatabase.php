<?php
require_once __DIR__ . '/../Utilities/Logger.php';

class SQLiteCacheDatabase
{
    private $db;
    private $logger;

    public function __construct(string $dbPath, Logger $logger)
    {
        $this->logger = $logger;
        $isNewDb = !file_exists($dbPath);
        $this->db = new PDO("sqlite:" . $dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if ($isNewDb) {
            $this->initializeDatabase();
            $this->logger->info("Инициализирована новая база данных кэша SQLite: $dbPath", __FILE__, __LINE__);
        } else {
            $this->checkAndMigrateDatabase();
        }
    }

    public function getDb(): PDO
    {
        return $this->db;
    }

    private function initializeDatabase(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS queries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                query TEXT NOT NULL UNIQUE,
                custom_params TEXT,
                config_hash TEXT,
                last_accessed TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS dft_cache (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                query_id INTEGER NOT NULL,
                metric_hash TEXT NOT NULL,
                metric_json TEXT NOT NULL,
                data_start INTEGER,
                step INTEGER,
                total_duration INTEGER,
                dft_rebuild_count INTEGER DEFAULT 0,
                labels_json TEXT,
                created_at INTEGER,
                anomaly_stats_json TEXT,
                dft_upper_json TEXT,
                dft_lower_json TEXT,
                upper_trend_json TEXT,
                lower_trend_json TEXT,
                last_accessed TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (query_id) REFERENCES queries(id),
                UNIQUE(query_id, metric_hash)
            )
        ");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_queries_query ON queries(query)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_dft_cache_query_id ON dft_cache(query_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_dft_cache_metric_hash ON dft_cache(metric_hash)");
    }

    private function checkAndMigrateDatabase(): void
    {
        $result = $this->db->query("PRAGMA table_info(queries)");
        $columns = $result->fetchAll(PDO::FETCH_ASSOC);
        $hasCustomParams = false;
        $hasConfigHash = false;
        foreach ($columns as $column) {
            if ($column['name'] === 'custom_params') {
                $hasCustomParams = true;
            }
            if ($column['name'] === 'config_hash') {
                $hasConfigHash = true;
            }
        }
        if (!$hasCustomParams) {
            $this->logger->warn("Добавление столбца custom_params в таблицу queries.", __FILE__, __LINE__);
            $this->db->exec("ALTER TABLE queries ADD COLUMN custom_params TEXT");
            $this->logger->info("Столбец custom_params добавлен в таблицу queries.", __FILE__, __LINE__);
        }
        if (!$hasConfigHash) {
            $this->logger->warn("Добавление столбца config_hash в таблицу queries.", __FILE__, __LINE__);
            $this->db->exec("ALTER TABLE queries ADD COLUMN config_hash TEXT");
            $this->logger->info("Столбец config_hash добавлен в таблицу queries.", __FILE__, __LINE__);
        }

        $result = $this->db->query("PRAGMA table_info(dft_cache)");
        $columns = $result->fetchAll(PDO::FETCH_ASSOC);
        $hasUpperTrend = false;
        $hasLowerTrend = false;
        foreach ($columns as $column) {
            if ($column['name'] === 'upper_trend_json') {
                $hasUpperTrend = true;
            }
            if ($column['name'] === 'lower_trend_json') {
                $hasLowerTrend = true;
            }
        }
        if (!$hasUpperTrend) {
            $this->logger->warn("Добавление столбца upper_trend_json в таблицу dft_cache.", __FILE__, __LINE__);
            $this->db->exec("ALTER TABLE dft_cache ADD COLUMN upper_trend_json TEXT");
            $this->logger->info("Столбец upper_trend_json добавлен в таблицу dft_cache.", __FILE__, __LINE__);
        }
        if (!$hasLowerTrend) {
            $this->logger->warn("Добавление столбца lower_trend_json в таблицу dft_cache.", __FILE__, __LINE__);
            $this->db->exec("ALTER TABLE dft_cache ADD COLUMN lower_trend_json TEXT");
            $this->logger->info("Столбец lower_trend_json добавлен в таблицу dft_cache.", __FILE__, __LINE__);
        }
    }
}
?>