<?php
declare(strict_types=1);

require_once __DIR__ . '/../Interfaces/LoggerInterface.php';
require_once __DIR__ . '/../Interfaces/GrafanaClientInterface.php';
require_once __DIR__ . '/../Interfaces/CacheManagerInterface.php';
require_once __DIR__ . '/../Interfaces/DFTProcessorInterface.php';
require_once __DIR__ . '/../Processors/FourierTransformer.php';
require_once __DIR__ . '/../Utilities/Logger.php';
require_once __DIR__ . '/../Clients/GrafanaProxyClient.php';
require_once __DIR__ . '/../Cache/CacheManagerFactory.php';
require_once __DIR__ . '/../Processors/DFTProcessor.php';

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
            $logLevel = Logger::{'LEVEL_' . strtoupper($this->config['log_level'] ?? 'INFO')};
            return new Logger($this->config['log_file'], $logLevel);
        };

        // CacheManager
        $this->services[CacheManagerInterface::class] = function () {
            $logger = $this->get(LoggerInterface::class);
            return CacheManagerFactory::create($this->config, $logger);
        };

        // GrafanaClient
        $this->services[GrafanaClientInterface::class] = function () {
            $logger = $this->get(LoggerInterface::class);
            $blacklist = $this->config['blacklist_datasource_ids'] ?? [];
            return new GrafanaProxyClient(
                $this->config['grafana_url'],
                $this->config['grafana_api_token'],
                $logger,
                $blacklist
            );
        };

        // FourierTransformer
        $this->services[FourierTransformerInterface::class] = function () {
            $logger = $this->get(LoggerInterface::class);
            return new FourierTransformer($logger);
        };

        // DFTProcessor
        $this->services[DFTProcessorInterface::class] = function () {
            $logger = $this->get(LoggerInterface::class);
            return new DFTProcessor($this->config, $logger);
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