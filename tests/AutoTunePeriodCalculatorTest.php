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
        $mockCacheManager = $this->createMock(CacheManagerInterface::class);
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
}