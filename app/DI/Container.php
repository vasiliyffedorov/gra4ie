<?php
declare(strict_types=1);

namespace App\DI;

use App\Interfaces\LoggerInterface;
use App\Interfaces\CacheManagerInterface;
use App\Interfaces\GrafanaClientInterface;
use App\Interfaces\DFTProcessorInterface;
use App\Utilities\Logger;
use App\Clients\GrafanaProxyClient;
use App\Cache\CacheManagerFactory;
use App\Processors\FourierTransformer;
use App\Processors\DFTProcessor;
use Psr\SimpleCache\SimpleCacheInterface;
use Exception;

class Container
{
    private array $services = [];
    private array $instances = [];
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->registerServices();
    }

    private function registerServices(): void
    {
        // Logger
        $this->services[LoggerInterface::class] = function () {
            $logLevel = \App\Utilities\Logger::{'LEVEL_' . strtoupper($this->config['log_level'] ?? 'INFO')};
            return new \App\Utilities\Logger($this->config['log_file'], $logLevel);
        };

        // CacheManager
        $this->services[CacheManagerInterface::class] = function () {
            $logger = $this->get(LoggerInterface::class);
            return CacheManagerFactory::create($this->config, $logger);
        };

        // GrafanaClient
        $this->services[GrafanaClientInterface::class] = function () {
            $logger = $this->get(LoggerInterface::class);
            $cacheManager = $this->get(CacheManagerInterface::class);
            $blacklist = $this->config['blacklist_datasource_ids'] ?? [];
            return new GrafanaProxyClient(
                $this->config['grafana_url'],
                $this->config['grafana_api_token'],
                $logger,
                $blacklist,
                $cacheManager
            );
        };

        // FourierTransformer
        $this->services[FourierTransformer::class] = function () {
            $logger = $this->get(LoggerInterface::class);
            return new FourierTransformer($logger);
        };

        // DFTProcessor
        $this->services[DFTProcessorInterface::class] = function () {
            $logger = $this->get(LoggerInterface::class);
            return new \App\Processors\DFTProcessor($this->config, $logger);
        };

        // PSR-16 Cache Adapter
        $this->services[SimpleCacheInterface::class] = function () {
            $logger = $this->get(LoggerInterface::class);
            return CacheManagerFactory::createPsrCache($this->config, $logger);
        };

        // Config (raw array)
        $this->services['config'] = fn() => $this->config;
    }

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (!isset($this->services[$id])) {
            throw new Exception("Service '$id' not found in container.");
        }

        $service = $this->services[$id]();
        if (is_object($service)) {
            $this->instances[$id] = $service;
        }

        return $service;
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]) || isset($this->instances[$id]);
    }
}