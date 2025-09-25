<?php
declare(strict_types=1);

namespace App\Tests;

use App\Processors\AnomalyDetector;
use App\Utilities\Logger;
use PHPUnit\Framework\TestCase;

class AnomalyDetectorTest extends TestCase
{
    private Logger $logger;
    private array $config;

    protected function setUp(): void
    {
        $this->logger = new Logger(__DIR__ . '/../logs/test.log', Logger::LEVEL_DEBUG);
        $this->config = [
            'corrdor_params' => [
                'step' => 60,
                'default_percentiles' => [
                    'duration' => 75,
                    'size' => 75,
                    'duration_multiplier' => 1.0,
                    'size_multiplier' => 1.0
                ]
            ],
            'cache' => [
                'percentiles' => '0,10,20,30,40,50,60,70,80,90,95,100'
            ]
        ];
    }

    public function testCompressHistoryWithLessThanOrEqual12Elements(): void
    {
        $detector = new AnomalyDetector($this->config, $this->logger);

        $reflection = new \ReflectionClass($detector);
        $method = $reflection->getMethod('compressHistory');
        $method->setAccessible(true);

        // Тест с 10 элементами
        $input = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        $result = $method->invoke($detector, $input);

        $this->assertCount(12, $result);
        $this->assertEquals([0, 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10], $result);

        // Тест с 12 элементами
        $input12 = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];
        $result12 = $method->invoke($detector, $input12);

        $this->assertCount(12, $result12);
        sort($input12);
        $this->assertEquals($input12, $result12);
    }

    public function testCompressHistoryWithMoreThan12Elements(): void
    {
        $detector = new AnomalyDetector($this->config, $this->logger);

        $reflection = new \ReflectionClass($detector);
        $method = $reflection->getMethod('compressHistory');
        $method->setAccessible(true);

        // Тест с 15 элементами
        $input = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15];
        $result = $method->invoke($detector, $input);

        $this->assertCount(12, $result);
        // Проверяем, что это перцентили
        $this->assertGreaterThanOrEqual(1, $result[0]); // 0-й перцентиль
        $this->assertLessThanOrEqual(15, $result[11]); // 100-й перцентиль
    }

    public function testCompressHistoryWithEmptyArray(): void
    {
        $detector = new AnomalyDetector($this->config, $this->logger);

        $reflection = new \ReflectionClass($detector);
        $method = $reflection->getMethod('compressHistory');
        $method->setAccessible(true);

        $input = [];
        $result = $method->invoke($detector, $input);

        $this->assertCount(12, $result);
        $this->assertEquals(array_fill(0, 12, 0), $result);
    }

    public function testInterpolatePercentile(): void
    {
        $detector = new AnomalyDetector($this->config, $this->logger);

        $reflection = new \ReflectionClass($detector);
        $method = $reflection->getMethod('interpolatePercentile');
        $method->setAccessible(true);

        $percentiles = [0, 10, 20, 30, 40, 50, 60, 70, 80, 90, 95, 100];
        $values = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 9.5, 10];

        // Тест точного совпадения
        $result = $method->invoke($detector, $percentiles, $values, 50.0);
        $this->assertEquals(5, $result);

        // Тест интерполяции между 70 и 80
        $result = $method->invoke($detector, $percentiles, $values, 75.0);
        $expected = 7 + 0.5 * (8 - 7); // 7.5
        $this->assertEquals(7.5, $result);

        // Тест ниже минимального
        $result = $method->invoke($detector, $percentiles, $values, -5.0);
        $this->assertEquals(0, $result);

        // Тест выше максимального
        $result = $method->invoke($detector, $percentiles, $values, 105.0);
        $this->assertEquals(10, $result);
    }

    public function testCalculateIntegralMetric(): void
    {
        $detector = new AnomalyDetector($this->config, $this->logger);

        $currentStats = [
            'durations' => [100, 200, 150],
            'sizes' => [1.0, 2.0, 1.5]
        ];

        $historicalStats = [
            'durations' => [50, 60, 70, 80, 90, 100, 110, 120, 130, 140, 145, 150], // перцентили
            'sizes' => [0.5, 0.6, 0.7, 0.8, 0.9, 1.0, 1.1, 1.2, 1.3, 1.4, 1.45, 1.5]
        ];

        $result = $detector->calculateIntegralMetric($currentStats, $historicalStats);

        $this->assertArrayHasKey('duration_concern', $result);
        $this->assertArrayHasKey('size_concern', $result);
        $this->assertArrayHasKey('total_concern', $result);

        // Для 75-го перцентиля: durations[7] = 120 (индекс 7 для 70-го? подождите
        // percentiles: 0,10,20,30,40,50,60,70,80,90,95,100
        // 75 между 70 (индекс 6: 110) и 80 (индекс 7: 120)
        // (75-70)/(80-70) = 0.5, так 110 + 0.5*(120-110) = 115
        // max current 200 > 115, ratio = 200/115 ≈ 1.739, concern = 1.739 - 1 = 0.739

        $this->assertGreaterThan(0, $result['duration_concern']);
        $this->assertGreaterThan(0, $result['size_concern']);
        $this->assertEquals($result['duration_concern'] + $result['size_concern'], $result['total_concern']);
    }

    public function testCalculateIntegralMetricWithEmptyStats(): void
    {
        $detector = new AnomalyDetector($this->config, $this->logger);

        $this->expectException(\InvalidArgumentException::class);
        $detector->calculateIntegralMetric([], []);
    }

    protected function tearDown(): void
    {
        unset($this->logger, $this->config);
    }
}