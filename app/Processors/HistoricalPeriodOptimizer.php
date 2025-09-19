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

        if (!isset($config['corrdor_params']['step']) || !isset($config['corrdor_params']['test_periods']) || !isset($config['corrdor_params']['fetch_timeout_sec'])) {
            throw new \InvalidArgumentException('Config must contain step, test_periods, fetch_timeout_sec');
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

        // Parse test_periods, e.g. "1m,5m,10m,30m,1h,6h,1d,7d,30d,90d"
        $testPeriodsStr = $this->config['corrdor_params']['test_periods'];
        if (is_array($testPeriodsStr)) {
            $testPeriodsStr = implode(',', $testPeriodsStr);
            $this->logger->debug("test_periods was array, joined to string");
        }
        $periodStrs = explode(',', $testPeriodsStr);
        $testPeriodsSec = [];
        foreach ($periodStrs as $pStr) {
            $pStr = trim($pStr);
            if (str_ends_with($pStr, 'm')) {
                $min = (int)substr($pStr, 0, -1);
                $testPeriodsSec[] = $min * 60;
            } elseif (str_ends_with($pStr, 'h')) {
                $hr = (int)substr($pStr, 0, -1);
                $testPeriodsSec[] = $hr * 3600;
            } elseif (str_ends_with($pStr, 'd')) {
                $day = (int)substr($pStr, 0, -1);
                $testPeriodsSec[] = $day * 86400;
            } else {
                $this->logger->warning("Invalid period string: $pStr");
            }
        }

        $now = time();
        $prevPeriodSec = 0;
        $datasourceType = 'unknown';
        $lastDataCount = 0;

        foreach ($testPeriodsSec as $periodSec) {
            if ($periodSec <= $prevPeriodSec) continue; // Skip if not increasing

            PerformanceMonitor::start('historical_fetch_test_' . ($periodSec / 86400) . 'd');

            $histStart = $now - $periodSec;
            $histEnd = $now;
            $rawData = $this->client->queryRange($query, $histStart, $histEnd, $step);

            $timeTaken = PerformanceMonitor::end('historical_fetch_test_' . ($periodSec / 86400) . 'd');

            if ($timeTaken > $this->config['corrdor_params']['fetch_timeout_sec']) {
                $this->logger->info("Fetch timeout for period $periodSec sec ($timeTaken s > {$this->config['corrdor_params']['fetch_timeout_sec']}s), stop at prev $prevPeriodSec sec");
                break;
            }

            if (empty($rawData)) {
                $this->logger->info("Empty data for period $periodSec sec (EOF/retention), stop at prev $prevPeriodSec sec");
                break;
            }

            $grouped = $this->dataProcessor->groupData($rawData);
            $data = $grouped[$labelsJson] ?? [];
            $dataCount = count($data);

            if ($dataCount == 0 || ($dataCount - $lastDataCount < ($this->config['corrdor_params']['min_points_increment'] ?? 5) && $dataCount > 20)) {
                $this->logger->info("No significant new data for period $periodSec sec (increment " . ($dataCount - $lastDataCount) . " < " . ($this->config['corrdor_params']['min_points_increment'] ?? 5) . ", total $dataCount >20), stop at prev $prevPeriodSec sec");
                break;
            }

            $datasourceType = $this->client->getLastDataSourceType();
            $prevPeriodSec = $periodSec;
            $lastDataCount = $dataCount;
            $this->logger->debug("Test fetch for $periodSec sec successful: $dataCount points in $timeTaken s");
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
}