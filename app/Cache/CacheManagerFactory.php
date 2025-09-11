<?php
declare(strict_types=1);

namespace App\Cache;

use App\Interfaces\CacheManagerInterface;
use App\Utilities\Logger;

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
}
?>