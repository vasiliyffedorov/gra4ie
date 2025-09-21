<?php
declare(strict_types=1);

namespace App\Cache;

use App\Interfaces\CacheManagerInterface;
use App\Interfaces\LoggerInterface;
use App\Cache\SQLiteCacheDatabase;
use App\Cache\SQLiteCacheIO;
use App\Cache\SQLiteCacheMaintenance;
use App\Cache\SQLiteCacheConfig;
use App\Cache\GrafanaMetricsCache;

class SQLiteCacheManager implements CacheManagerInterface
{
    private SQLiteCacheDatabase $dbManager;
    private SQLiteCacheIO $ioManager;
    private SQLiteCacheMaintenance $maintenanceManager;
    private SQLiteCacheConfig $configManager;
    private GrafanaMetricsCache $grafanaMetricsCache;
    private LoggerInterface $logger;
    private int $maxTtl;
    private array $config;

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
            $this->grafanaMetricsCache = new GrafanaMetricsCache($this->dbManager, $logger);
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

    // L1 autoscale cache (no TTL cleanup)
    public function saveAutoscaleL1(string $query, string $labelsJson, array $info): bool
    {
        return $this->ioManager->saveAutoscaleL1($query, $labelsJson, $info);
    }

    public function loadAutoscaleL1(string $query, string $labelsJson): ?array
    {
        return $this->ioManager->loadAutoscaleL1($query, $labelsJson);
    }

    // Permanent metrics cache
    public function saveMetricsCacheL1(string $query, string $labelsJson, array $info): bool
    {
        return $this->ioManager->saveMetricsCacheL1($query, $labelsJson, $info);
    }

    public function loadMetricsCacheL1(string $query, string $labelsJson): ?array
    {
        return $this->ioManager->loadMetricsCacheL1($query, $labelsJson);
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
        return $this->ioManager->saveGrafanaMetrics($metrics);
    }

    public function loadGrafanaMetrics(): ?array
    {
        return $this->ioManager->loadGrafanaMetrics();
    }

    public function loadMaxPeriod(string $metricKey): ?float
    {
        return $this->ioManager->loadMaxPeriod($metricKey);
    }

    public function saveMaxPeriod(string $metricKey, float $maxPeriodDays, string $datasourceType = ''): bool
    {
        return $this->ioManager->saveMaxPeriod($metricKey, $maxPeriodDays);
    }

    public function cleanupLevel2(int $ttl): void
    {
        try {
            $db = $this->dbManager->getDb();
            $stmt = $db->prepare("DELETE FROM dft_cache WHERE last_accessed < datetime('now', '-{$ttl} seconds')");
            $deleted = $stmt->execute();
            $this->logger->info("Очищено {$deleted} записей из уровня 2 кэша (TTL $ttl sec)");
        } catch (\PDOException $e) {
            $this->logger->error("Ошибка очистки уровня 2 кэша: " . $e->getMessage());
        }
    }
}
?>