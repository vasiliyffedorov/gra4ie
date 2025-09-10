<?php
declare(strict_types=1);

require_once __DIR__ . '/../Interfaces/CacheManagerInterface.php';
require_once __DIR__ . '/../Utilities/Logger.php';
require_once __DIR__ . '/SQLiteCacheManager.php';

class CacheManagerFactory {
    public static function create(array $config, LoggerInterface $logger): CacheManagerInterface {
        $dbConfig = $config['cache']['database'];
        if (!extension_loaded('pdo_sqlite')) {
            throw new Exception("PDO SQLite extension is not loaded");
        }
        return new SQLiteCacheManager(
            $dbConfig['path'],
            $logger,
            $dbConfig['max_ttl'] ?? 86400,
            $config
        );
    }
}
?>