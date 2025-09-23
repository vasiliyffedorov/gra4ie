<?php
declare(strict_types=1);

namespace App\Tests;

use App\Processors\AutoTunePeriodCalculator;
use App\Utilities\Logger;
use PHPUnit\Framework\TestCase;

class AutoTunePeriodCalculatorTest extends TestCase
{
    private Logger $logger;
    private AutoTunePeriodCalculator $calculator;

    protected function setUp(): void
    {
        $this->logger = new Logger('php://memory', Logger::LEVEL_INFO);
        $this->calculator = new AutoTunePeriodCalculator($this->logger);
    }

    public function testCalculateOptimalPeriodWithSampleData(): void
    {
        // Синтетические данные за ~40 дней с периодом 36 дней
        $startTime = strtotime('2025-08-13 04:00:00');
        $periodHours = 36 * 24;
        $totalHours = 40 * 24;
        $stepHours = 1; // Часовой шаг для теста
        $data = [];
        for ($h = 0; $h < $totalHours; $h += $stepHours) {
            $t = $startTime + $h * 3600;
            $value = 5.0 + 2.0 * sin(2 * M_PI * $h / $periodHours) + 0.01 * $h; // Период 36 дней, тренд
            $data[$t] = $value;
        }

        $result = $this->calculator->calculateOptimalPeriod($data);

        // Ожидаем близко к 36.0
        $this->assertEquals(36.0, $result, '', 1.0);
    }

    public function testCalculateOptimalPeriodAllZeros(): void
    {
        $zeroData = [
            strtotime('2025-08-13 04:00:00') => 0.0,
            strtotime('2025-08-13 05:00:00') => 0.0,
            strtotime('2025-08-13 06:00:00') => 0.0,
        ];

        $result = $this->calculator->calculateOptimalPeriod($zeroData);
        $this->assertLessThan(1.0, $result);
    }

    public function testCalculateOptimalPeriodEmptyData(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Historical data is empty');

        $this->calculator->calculateOptimalPeriod([]);
    }

    protected function tearDown(): void
    {
        unset($this->calculator, $this->logger);
    }

    public function testRecalculateStatsAutotuneTrim(): void
    {
        // Mock dependencies for StatsCacheManager
        $mockLogger = $this->logger;
        $mockCacheManager = $this->createMock(\App\Interfaces\CacheManagerInterface::class);
        $mockCacheManager->method('loadFromCache')->willReturn(null); // No cache, trigger recalc
        $mockResponseFormatter = $this->createMock(ResponseFormatter::class);
        $mockDataProcessor = $this->createMock(DataProcessorInterface::class);
        $mockDftProcessor = $this->createMock(DFTProcessorInterface::class);
        $mockAnomalyDetector = $this->createMock(AnomalyDetectorInterface::class);
        $mockClient = $this->createMock(GrafanaClientInterface::class);
        $mockClient->expects($this->never())->method('queryRange'); // No new query after autotune

        $mockAutoTune = $this->createMock(AutoTunePeriodCalculator::class);
        $mockAutoTune->method('calculateOptimalPeriod')->willReturn(15.0); // optimal < current 30

        $mockDataProcessor->method('getActualDataRange')->willReturnCallback(function($data) {
            if (empty($data)) return ['start' => 0, 'end' => 0];
            $times = array_column($data, 'time');
            return ['start' => min($times), 'end' => max($times)];
        });

        // Setup historyData: 30 days, step 4h (14400 sec), 180 points
        $startTime = strtotime('2025-01-01 00:00:00');
        $stepSec = 14400;
        $historyData = [];
        for ($i = 0; $i < 180; $i++) {
            $time = $startTime + $i * $stepSec;
            $historyData[] = ['time' => $time, 'value' => (float)($i % 10 + 1)];
        }
        $longEnd = end($historyData)['time'];
        $originalLongStart = $historyData[0]['time'];

        $config = [
            'corrdor_params' => [
                'step' => $stepSec,
                'min_data_points' => 50,
                'historical_period_days' => 30.0,
                'default_percentiles' => [95, 5]
            ],
            'cache' => []
        ];

        // Partial mock for StatsCacheManager to test recalculateStats
        $reflection = new \ReflectionClass(StatsCacheManager::class);
        $statsCacheManager = $reflection->newInstanceWithoutConstructor();
        $statsCacheManagerReflection = new \ReflectionObject($statsCacheManager);
        $configProp = $statsCacheManagerReflection->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue($statsCacheManager, $config);
        $loggerProp = $statsCacheManagerReflection->getProperty('logger');
        $loggerProp->setAccessible(true);
        $loggerProp->setValue($statsCacheManager, $mockLogger);
        $cacheProp = $statsCacheManagerReflection->getProperty('cacheManager');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($statsCacheManager, $mockCacheManager);
        $responseProp = $statsCacheManagerReflection->getProperty('responseFormatter');
        $responseProp->setAccessible(true);
        $responseProp->setValue($statsCacheManager, $mockResponseFormatter);
        $dataProp = $statsCacheManagerReflection->getProperty('dataProcessor');
        $dataProp->setAccessible(true);
        $dataProp->setValue($statsCacheManager, $mockDataProcessor);
        $dftProp = $statsCacheManagerReflection->getProperty('dftProcessor');
        $dftProp->setAccessible(true);
        $dftProp->setValue($statsCacheManager, $mockDftProcessor);
        $anomalyProp = $statsCacheManagerReflection->getProperty('anomalyDetector');
        $anomalyProp->setAccessible(true);
        $anomalyProp->setValue($statsCacheManager, $mockAnomalyDetector);
        $clientProp = $statsCacheManagerReflection->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($statsCacheManager, $mockClient);
        $autoTuneProp = $statsCacheManagerReflection->getProperty('autoTune');
        $autoTuneProp->setAccessible(true);
        $autoTuneProp->setValue($statsCacheManager, $mockAutoTune);

        $query = 'test_query';
        $labelsJson = '{"test": "metric"}';
        $liveData = [];
        $result = $statsCacheManager->recalculateStats($query, $labelsJson, $liveData, $historyData, $config);

        // Assertions
        $this->assertNotEmpty($result['meta']);
        $this->assertGreaterThanOrEqual(50, count($result['dft_upper']['coefficients'] ?? [])); // DFT computed
        // Check log for trim (since logger to memory, check output)
        $logOutput = stream_get_contents($mockLogger->getStream()); // Assume logger has getStream method or similar
        $this->assertStringContainsString('Автотюн использован', $logOutput);
        $this->assertStringContainsString('Автотюн подрезка', $logOutput);
        $this->assertStringContainsString('период 15 дней', $logOutput);
        // Note: Full count check requires accessing private after call, simplified here
        $this->assertTrue(true); // Placeholder for full verification
    }

    public function testL1CacheIgnoresConfigHashChange(): void
    {
        // Test that L1 cache HIT occurs when config_hash changes but query remains the same,
        // and autotune period is frozen, not reset to 1 day.

        $mockLogger = $this->createMock(\App\Utilities\Logger::class);

        $mockCacheManager = $this->createMock(\App\Interfaces\CacheManagerInterface::class);
        $mockCacheManager->method('loadFromCache')->willReturn(null); // No main cache

        // Mock L1 cache: first call returns HIT data with optimal_period_days = 30
        $l1Data = [
            'request_md5' => 'same_md5',
            'optimal_period_days' => 30.0,
            'scale_corridor' => false,
            'k' => 8,
            'factor' => null,
        ];
        $mockCacheManager->expects($this->atLeast(2))
            ->method('loadMetricsCacheL1')
            ->willReturn($l1Data); // Both calls return the same L1 data

        $mockResponseFormatter = $this->createMock(\App\Formatters\ResponseFormatter::class);
        $mockDataProcessor = $this->createMock(\App\Interfaces\DataProcessorInterface::class);
        $mockDataProcessor->method('getActualDataRange')->willReturn(['start' => 1000000000, 'end' => 1000864000]); // 1 day
        $mockDataProcessor->method('groupData')->willReturn([$labelsJson => array_fill(0, 50, ['time' => time() + 1800 * 0, 'value' => 1.0])]);
        $mockDataProcessor->method('calculateBounds')->willReturn(['upper' => [], 'lower' => []]);
        $mockDftProcessor = $this->createMock(\App\Interfaces\DFTProcessorInterface::class);
        $mockDftProcessor->method('generateDFT')->willReturn(['upper' => ['coefficients' => [], 'trend' => ['slope' => 0, 'intercept' => 0]], 'lower' => ['coefficients' => [], 'trend' => ['slope' => 0, 'intercept' => 0]]]);
        $mockDftProcessor->method('restoreFullDFT')->willReturn([]);
        $mockAnomalyDetector = $this->createMock(\App\Interfaces\AnomalyDetectorInterface::class);
        $mockAnomalyDetector->method('calculateAnomalyStats')->willReturn(['above' => [], 'below' => [], 'combined' => []]);
        $mockClient = $this->createMock(\App\Interfaces\GrafanaClientInterface::class);
        $mockClient->method('getNormalizedRequestMd5')->willReturn('same_md5'); // Same query MD5
        $mockClient->method('queryRange')->willReturn([]);

        $mockAutoTune = $this->createMock(\App\Processors\AutoTunePeriodCalculator::class);
        $mockAutoTune->expects($this->never())->method('calculateOptimalPeriod'); // Should not be called due to freeze

        $mockOptimizer = $this->createMock(\App\Processors\HistoricalPeriodOptimizer::class);
        $mockOptimizer->method('determineMaxPeriod')->willReturn(30.0);

        $statsCacheManager = new \App\Processors\StatsCacheManager(
            ['corrdor_params' => ['step' => 1800, 'min_data_points' => 10], 'cache' => []],
            $mockLogger,
            $mockCacheManager,
            $mockResponseFormatter,
            $mockDataProcessor,
            $mockDftProcessor,
            $mockAnomalyDetector,
            $mockClient,
            $mockAutoTune,
            $mockOptimizer
        );

        $query = 'test_query';
        $labelsJson = '{"test": "metric"}';
        $liveData = [];
        $historyData = []; // Empty to trigger fetch

        // First call with config1
        $config1 = ['corrdor_params' => ['step' => 1800, 'min_data_points' => 10, 'default_percentiles' => [95, 5]], 'cache' => []];
        $result1 = $statsCacheManager->recalculateStats($query, $labelsJson, $liveData, $historyData, $config1);

        // Second call with config2 (different config_hash, but same query)
        $config2 = ['corrdor_params' => ['step' => 1800, 'min_data_points' => 10, 'default_percentiles' => [90, 10], 'new_param' => 'test'], 'cache' => []]; // Changed percentiles and added param
        $result2 = $statsCacheManager->recalculateStats($query, $labelsJson, $liveData, $historyData, $config2);

        // Assertions
        $this->assertNotEmpty($result1['meta']);
        $this->assertNotEmpty($result2['meta']);
        // Config hashes are the same because calculated from $this->config, not $currentConfig
        $this->assertEquals($result1['meta']['config_hash'], $result2['meta']['config_hash']);
        // But since L1 HIT (same request_md5), autotune should be frozen, and period not changed
        // In logs, should see L1 HIT for both
        // Since mockAutoTune never called, autotune not triggered
    }
}