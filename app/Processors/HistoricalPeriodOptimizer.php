<?php
declare(strict_types=1);

namespace App\Processors;

use App\Clients\GrafanaProxyClient;
use App\Interfaces\CacheManagerInterface;
use App\Interfaces\DataProcessorInterface;
use App\Interfaces\LoggerInterface;
use App\Utilities\PerformanceMonitor;
use App\Utilities\Logger;

class HistoricalPeriodOptimizer
{
    private array $config;
    private LoggerInterface $logger;
    private CacheManagerInterface $cacheManager;
    private GrafanaProxyClient $client;
    private DataProcessorInterface $dataProcessor;

    public function __construct(
        array $config,
        LoggerInterface $logger,
        CacheManagerInterface $cacheManager,
        GrafanaProxyClient $client,
        DataProcessorInterface $dataProcessor
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->cacheManager = $cacheManager;
        $this->client = $client;
        $this->dataProcessor = $dataProcessor;

        if (!isset($config['corridor_params']['step']) || !isset($config['corridor_params']['fetch_timeout_sec'])) {
            throw new \InvalidArgumentException('Config must contain step, fetch_timeout_sec');
        }
    }

    public function determineMaxPeriod(string $query, string $labelsJson, int $step): float
    {
        $metricKey = $query . '|' . $labelsJson;
        $maxPeriodDays = $this->cacheManager->loadMaxPeriod($metricKey);
        if ($maxPeriodDays !== null) {
            $this->logger->info("Max period loaded from level1 cache for $metricKey: $maxPeriodDays days");
            return $maxPeriodDays;
        }

        // Parse test periods from config
        $testPeriods = $this->config['corridor_params']['test_periods'] ?? '1m,5m,10m,30m,1h,6h,1d,2d,7d,30d';
        $testPeriodsSec = $this->parseTestPeriods($testPeriods);

        $now = time();
        $prevPeriodSec = 0;
        $datasourceType = 'unknown';

        foreach ($testPeriodsSec as $periodSec) {
            PerformanceMonitor::start('historical_fetch_test_' . ($periodSec / 86400) . 'd');

            $histStart = $now - $periodSec;
            $histEnd = $now;
            $rawData = $this->client->queryRange($query, $histStart, $histEnd, $step);

            $timeTaken = PerformanceMonitor::end('historical_fetch_test_' . ($periodSec / 86400) . 'd');

            if ($timeTaken >= 5) {
                $this->logger->info("Fetch time >= 5s for period $periodSec sec ($timeTaken s), stop at prev $prevPeriodSec sec");
                break;
            }

            if (empty($rawData)) {
                $this->logger->info("Empty data for period $periodSec sec, stop at prev $prevPeriodSec sec");
                break;
            }

            $datasourceType = $this->client->getLastDataSourceType();
            $prevPeriodSec = $periodSec;
            $this->logger->debug("Test fetch for $periodSec sec successful: in $timeTaken s");
        }

        if ($prevPeriodSec == 0) {
            $prevPeriodSec = 86400; // Fallback to 1 day
            $this->logger->warning("No successful test period, fallback to 1 day for $metricKey");
        }

        $maxPeriodDays = $prevPeriodSec / 86400.0;
        $this->cacheManager->saveMaxPeriod($metricKey, $maxPeriodDays, $datasourceType);

        $this->logger->info("Determined max period for $metricKey: $maxPeriodDays days (datasource: $datasourceType), stopped at $prevPeriodSec sec");

        return $maxPeriodDays;
    }

    public function updateConfig(array $config): void
    {
        $this->config = $config;
        $this->dataProcessor->updateConfig($config);
    }

    /**
     * Parse test periods into seconds array.
     * Accepts string: "1m,5m,10m,30m,1h,6h,1d,2d,7d,30d"
     * Or array: ["1m", "5m", ...]
     */
    private function parseTestPeriods(string|array $periods): array
    {
        if (is_string($periods)) {
            $periods = array_map('trim', explode(',', $periods));
        }

        $seconds = [];
        foreach ($periods as $period) {
            $seconds[] = $this->periodToSeconds($period);
        }

        // Sort ascending
        sort($seconds);
        return $seconds;
    }

    /**
     * Convert period string to seconds.
     * Supports: m (minutes), h (hours), d (days)
     */
    private function periodToSeconds(string $period): int
    {
        $unit = substr($period, -1);
        $value = (int)substr($period, 0, -1);

        return match ($unit) {
            'm' => $value * 60,
            'h' => $value * 3600,
            'd' => $value * 86400,
            default => throw new \InvalidArgumentException("Unknown period unit: $unit"),
        };
    }
}