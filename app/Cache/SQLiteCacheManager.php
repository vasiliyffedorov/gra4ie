<?php
declare(strict_types=1);

require_once __DIR__ . '/../Interfaces/CacheManagerInterface.php';
require_once __DIR__ . '/../Utilities/Logger.php';
require_once __DIR__ . '/SQLiteCacheDatabase.php';
require_once __DIR__ . '/SQLiteCacheIO.php';
require_once __DIR__ . '/SQLiteCacheMaintenance.php';
require_once __DIR__ . '/SQLiteCacheConfig.php';

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
            $this->logger->error("Ошибка инициализации SQLiteCacheManager: " . $e->getMessage(), __FILE__, __LINE__);
            throw new Exception("Не удалось инициализировать кэш SQLite");
        }
    }

    // Делегирование методов ввода-вывода
    public function generateCacheKey(string $query, string $labelsJson): string
    {
        return $this->ioManager->generateCacheKey($query, $labelsJson);
    }

    public function saveToCache(string $query, string $labelsJson, array $data, array $currentConfig): bool
    {
        return $this->ioManager->saveToCache($query, $labelsJson, $data, $currentConfig);
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

    public function shouldRecreateCache(string $query, string $labelsJson, array $currentConfig): bool
    {
        return $this->ioManager->shouldRecreateCache($query, $labelsJson, $currentConfig);
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
}
?>