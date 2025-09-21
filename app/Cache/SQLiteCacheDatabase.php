<?php
declare(strict_types=1);

namespace App\Cache;

use App\Utilities\Logger;

class SQLiteCacheDatabase
{
    private $db;
    private $logger;

    public function __construct(string $dbPath, Logger $logger)
    {
        $this->logger = $logger;
        $isNewDb = !file_exists($dbPath);

        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->db = new \PDO("sqlite:" . $dbPath);
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        if ($isNewDb) {
            $this->initializeDatabase();
            $this->logger->info("Инициализирована новая база данных кэша SQLite: $dbPath");
        } else {
            $this->checkAndMigrateDatabase();
        }
    }

    public function getDb(): \PDO
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
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS grafana_metrics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                query TEXT NOT NULL UNIQUE,
                payload_json TEXT NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_grafana_metrics_updated_at ON grafana_metrics(updated_at)");
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS metrics_max_periods (
                metric_key TEXT PRIMARY KEY,
                max_period_days REAL NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");


        // Permanent cache table for metrics
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS metrics_cache_permanent (
                query_id INTEGER NOT NULL,
                metric_hash TEXT NOT NULL,
                request_md5 TEXT NOT NULL,
                optimal_period_days REAL NULL,
                scale_corridor INTEGER NOT NULL DEFAULT 0,
                k INTEGER NOT NULL DEFAULT 8,
                factor REAL NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (query_id, metric_hash)
            )
        ");
        // Optional indices for performance
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_metrics_cache_permanent_query_id ON metrics_cache_permanent(query_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_metrics_cache_permanent_request_md5 ON metrics_cache_permanent(request_md5)");

        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_queries_query ON queries(query)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_dft_cache_query_id ON dft_cache(query_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_dft_cache_metric_hash ON dft_cache(metric_hash)");
    }

    private function checkAndMigrateDatabase(): void
    {
        // remove deprecated autoscale_l1 table if exists
        try {
            $autoscaleL1Exists = $this->db->query(\sprintf("SELECT name FROM sqlite_master WHERE type='table' AND name='autoscale_l1'"))->fetchColumn();
            if ($autoscaleL1Exists) {
                $this->logger->info("Удаление устаревшей таблицы autoscale_l1.");
                $this->db->exec("DROP TABLE IF EXISTS autoscale_l1");
                $this->logger->info("Таблица autoscale_l1 удалена.");
            }
        } catch (\PDOException $e) {
            $this->logger->error("Ошибка при удалении таблицы autoscale_l1: " . $e->getMessage());
        }

        // ensure metrics_cache_permanent
        try {
            $mcl1Exists = $this->db->query(\sprintf("SELECT name FROM sqlite_master WHERE type='table' AND name='metrics_cache_permanent'"))->fetchColumn();
            if (!$mcl1Exists) {
                $this->logger->warning("Создание таблицы metrics_cache_permanent.");
                $this->db->exec("
                    CREATE TABLE IF NOT EXISTS metrics_cache_permanent (
                        query_id INTEGER NOT NULL,
                        metric_hash TEXT NOT NULL,
                        request_md5 TEXT NOT NULL,
                        optimal_period_days REAL NULL,
                        scale_corridor INTEGER NOT NULL DEFAULT 0,
                        k INTEGER NOT NULL DEFAULT 8,
                        factor REAL NULL,
                        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (query_id, metric_hash)
                    )
                ");
                $this->db->exec("CREATE INDEX IF NOT EXISTS idx_metrics_cache_permanent_query_id ON metrics_cache_permanent(query_id)");
                $this->db->exec("CREATE INDEX IF NOT EXISTS idx_metrics_cache_permanent_request_md5 ON metrics_cache_permanent(request_md5)");
                $this->logger->info("Таблица metrics_cache_permanent создана.");
            }
        } catch (\PDOException $e) {
            $this->logger->error("Ошибка при создании таблицы metrics_cache_permanent: " . $e->getMessage());
        }

        // ensure grafana_metrics
        try {
            $gmExists = $this->db->query(\sprintf("SELECT name FROM sqlite_master WHERE type='table' AND name='grafana_metrics'"))->fetchColumn();
            if (!$gmExists) {
                $this->logger->warning("Создание таблицы grafana_metrics.");
                $this->db->exec("
                    CREATE TABLE IF NOT EXISTS grafana_metrics (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        query TEXT NOT NULL UNIQUE,
                        payload_json TEXT NOT NULL,
                        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    )
                ");
                $this->db->exec("CREATE INDEX IF NOT EXISTS idx_grafana_metrics_updated_at ON grafana_metrics(updated_at)");
                $this->logger->info("Таблица grafana_metrics создана.");
            }
        } catch (\PDOException $e) {
            $this->logger->error("Ошибка при создании таблицы grafana_metrics: " . $e->getMessage());
        }

        // ensure metrics_max_periods
        try {
            $mmpExists = $this->db->query(\sprintf("SELECT name FROM sqlite_master WHERE type='table' AND name='metrics_max_periods'"))->fetchColumn();
            if (!$mmpExists) {
                $this->logger->warning("Создание таблицы metrics_max_periods.");
                $this->db->exec("
                    CREATE TABLE IF NOT EXISTS metrics_max_periods (
                        metric_key TEXT PRIMARY KEY,
                        max_period_days REAL NOT NULL,
                        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    )
                ");
                $this->logger->info("Таблица metrics_max_periods создана.");
            }
        } catch (\PDOException $e) {
            $this->logger->error("Ошибка при создании таблицы metrics_max_periods: " . $e->getMessage());
        }
    }
}
?>