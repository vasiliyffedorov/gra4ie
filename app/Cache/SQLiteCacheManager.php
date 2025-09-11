<?php
declare(strict_types=1);

namespace App\Cache;

use App\Interfaces\CacheManagerInterface;
use App\Interfaces\LoggerInterface;
use App\Cache\SQLiteCacheDatabase;
use App\Cache\SQLiteCacheIO;
use App\Cache\SQLiteCacheMaintenance;
use App\Cache\SQLiteCacheConfig;

class SQLiteCacheManager implements CacheManagerInterface
{
    private $dbManager;
    private $ioManager;
    private $maintenanceManager;
    private $configManager;
    private LoggerInterface $logger;
    private $maxTtl;
    private $config;

    public function __construct(string $dbPath, LoggerInterface $logger, int $maxTtl = 86400, array $config = [])
    {
        $this->logger = $logger;
        $this->maxTtl = $maxTtl;
        $this->config = $config;

        try {
            // Инициализируем менеджеры
            $this->dbManager = new SQLiteCacheDatabase($dbPath, $logger);
            $this->ioManager = new SQLiteCacheIO($this->dbManager, $logger, $maxTtl, $config);
            $this->maintenanceManager = new SQLiteCacheMaintenance($this->dbManager, $logger);
            $this->configManager = new SQLiteCacheConfig($logger);
        } catch (Exception $e) {
            $this->logger->error("Ошибка инициализации SQLiteCacheManager: " . $e->getMessage());
            throw new Exception("Не удалось инициализировать кэш SQLite");
        }
    }

    // Делегирование методов ввода-вывода
    public function generateCacheKey(string $query, string $labelsJson): string
    {
        return $this->ioManager->generateCacheKey($query, $labelsJson);
    }

    public function saveToCache(string $query, string $labelsJson, array $payload, array $config): void
    {
        $this->ioManager->saveToCache($query, $labelsJson, $payload, $config);
    }

    public function loadFromCache(string $query, string $labelsJson): ?array
    {
        return $this->ioManager->loadFromCache($query, $labelsJson);
    }

    public function getAllCachedMetrics(string $query): array
    {
        return $this->ioManager->getAllCachedMetrics($query);
    }

    public function checkCacheExists(string $query, string $labelsJson): bool
    {
        return $this->ioManager->checkCacheExists($query, $labelsJson);
    }

    public function shouldRecreateCache(string $query, string $labelsJson, array $config): bool
    {
        return $this->ioManager->shouldRecreateCache($query, $labelsJson, $config);
    }


    // Делегирование методов обслуживания
    public function cleanupOldEntries(int $maxAgeDays = 30): void
    {
        $this->maintenanceManager->cleanupOldEntries($maxAgeDays);
    }

    // Делегирование методов конфигурации
    public function getOrCreateQueryId(string $query, ?string $customParams = null, ?string $configHash = null): int
    {
        return $this->configManager->getOrCreateQueryId($this->dbManager, $query, $customParams, $configHash);
    }

    public function getCustomParams(string $query): ?string
    {
        return $this->configManager->getCustomParams($this->dbManager, $query);
    }

    public function resetCustomParams(string $query): bool
    {
        return $this->configManager->resetCustomParams($this->dbManager, $query);
    }

    public function createConfigHash(array $config): string
    {
        return $this->configManager->createConfigHash($config);
    }
    public function saveGrafanaMetrics(array $metrics): bool
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
        } catch (\PDOException $e) {
            $this->logger->error("Ошибка сохранения кэша метрик Grafana: " . $e->getMessage());
            return false;
        }
    }

    public function loadGrafanaMetrics(): ?array
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
        } catch (\PDOException $e) {
            $this->logger->error("Ошибка загрузки кэша метрик Grafana: " . $e->getMessage());
            return null;
        }
    }
}
?>