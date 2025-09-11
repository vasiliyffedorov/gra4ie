<?php
declare(strict_types=1);

namespace App\Cache;

use App\Interfaces\CacheManagerInterface;
use App\Utilities\Logger;
use Psr\SimpleCache\SimpleCacheInterface;
use App\Cache\PsrDftCacheAdapter;
use Exception;

class CacheManagerFactory {
    public static function create(array $config, Logger $logger): CacheManagerInterface {
        $dbConfig = $config['cache']['database'];
        if (!extension_loaded('pdo_sqlite')) {
            throw new Exception("PDO SQLite extension is not loaded");
        }
        return new \App\Cache\SQLiteCacheManager(
            $dbConfig['path'],
            $logger,
            $dbConfig['max_ttl'] ?? 86400,
            $config
        );
    }

    public static function createPsrCache(array $config, Logger $logger): SimpleCacheInterface {
        $cacheManager = self::create($config, $logger);
        return new PsrDftCacheAdapter($cacheManager, $logger);
    }
}
?>