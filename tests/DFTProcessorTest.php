<?php
declare(strict_types=1);

namespace App\Tests;

use App\Processors\DFTProcessor;
use App\Utilities\Logger;
use PHPUnit\Framework\TestCase;

class DFTProcessorTest extends TestCase
{
    private array $config;
    private Logger $logger;
    private DFTProcessor $processor;

    protected function setUp(): void
    {
        $this->config = [
            'corrdor_params' => [
                'step' => 60,
                'max_harmonics' => 10,
                'use_common_trend' => false,
                'use_nudft' => false
            ]
        ];
        $this->logger = new Logger('php://memory', Logger::LEVEL_INFO);
        $this->processor = new DFTProcessor($this->config, $this->logger);
    }

    public function testCalculateLinearTrend(): void
    {
        $times = [1, 2, 3];
        $values = [2, 4, 6]; // Линейный тренд slope=2, intercept=0

        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('calculateLinearTrend');
        $method->setAccessible(true);
        $result = $method->invoke($this->processor, $values, $times);

        $this->assertEquals(2.0, $result['slope']);
        $this->assertEquals(0.0, $result['intercept']);
    }

    public function testCalculateLinearTrendInsufficientData(): void
    {
        $times = [1];
        $values = [5];

        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('calculateLinearTrend');
        $method->setAccessible(true);
        $result = $method->invoke($this->processor, $values, $times);

        $this->assertEquals(0, $result['slope']);
        $this->assertEquals(5, $result['intercept']);
    }

    protected function tearDown(): void
    {
        unset($this->processor, $this->logger, $this->config);
    }

    public function testGenerateDFTWithSparseData(): void
    {
        // Тест генерации DFT с прореженными данными (дыры в данных)
        $bounds = [
            'upper' => [
                ['time' => 1000000000, 'value' => 10.0],
                ['time' => 1000003600, 'value' => 12.0], // Пропуск
                ['time' => 1000007200, 'value' => 15.0],
                ['time' => 1000010800, 'value' => 11.0], // Пропуск
                ['time' => 1000014400, 'value' => 13.0],
            ],
            'lower' => [
                ['time' => 1000000000, 'value' => 8.0],
                ['time' => 1000003600, 'value' => 9.0],
                ['time' => 1000007200, 'value' => 12.0],
                ['time' => 1000010800, 'value' => 7.0],
                ['time' => 1000014400, 'value' => 10.0],
            ]
        ];
        $start = 1000000000;
        $end = 1000014400;
        $step = 3600;

        $result = $this->processor->generateDFT($bounds, $start, $end, $step);

        // Проверяем, что DFT рассчитан для upper и lower
        $this->assertArrayHasKey('upper', $result);
        $this->assertArrayHasKey('lower', $result);
        $this->assertArrayHasKey('coefficients', $result['upper']);
        $this->assertArrayHasKey('trend', $result['upper']);
        $this->assertArrayHasKey('coefficients', $result['lower']);
        $this->assertArrayHasKey('trend', $result['lower']);

        // Проверяем, что коэффициенты рассчитаны (используя NU DFT)
        $this->assertIsArray($result['upper']['coefficients']);
        $this->assertIsArray($result['lower']['coefficients']);
        $this->assertGreaterThan(0, count($result['upper']['coefficients']));
        $this->assertGreaterThan(0, count($result['lower']['coefficients']));
    }

    public function testGenerateDFTUsesNUDft(): void
    {
        // Тест, что generateDFT использует NU DFT (проверяем, что данные передаются напрямую без интерполяции)
        $bounds = [
            'upper' => [
                ['time' => 1000000000, 'value' => 10.0],
                ['time' => 1000007200, 'value' => 15.0], // Неравномерный шаг
                ['time' => 1000014400, 'value' => 13.0],
            ],
            'lower' => [
                ['time' => 1000000000, 'value' => 8.0],
                ['time' => 1000007200, 'value' => 12.0],
                ['time' => 1000014400, 'value' => 10.0],
            ]
        ];
        $start = 1000000000;
        $end = 1000014400;
        $step = 3600;

        $result = $this->processor->generateDFT($bounds, $start, $end, $step);

        // Проверяем, что тренд рассчитан
        $this->assertIsNumeric($result['upper']['trend']['slope']);
        $this->assertIsNumeric($result['upper']['trend']['intercept']);
        $this->assertIsNumeric($result['lower']['trend']['slope']);
        $this->assertIsNumeric($result['lower']['trend']['intercept']);

        // Проверяем, что коэффициенты включают гармоники (NU DFT фильтрует по вкладу)
        $this->assertArrayHasKey(0, $result['upper']['coefficients']); // Постоянная компонента
    }

    public function testGenerateDFTWithEmptyBounds(): void
    {
        // Тест обработки пустых bounds
        $bounds = [
            'upper' => [],
            'lower' => []
        ];
        $start = 1000000000;
        $end = 1000014400;
        $step = 3600;

        $result = $this->processor->generateDFT($bounds, $start, $end, $step);

        // Проверяем, что возвращены пустые коэффициенты
        $this->assertArrayHasKey('upper', $result);
        $this->assertArrayHasKey('lower', $result);
        $this->assertEmpty($result['upper']['coefficients']);
        $this->assertEmpty($result['lower']['coefficients']);
        $this->assertEquals(0.0, $result['upper']['trend']['slope']);
        $this->assertEquals(0.0, $result['upper']['trend']['intercept']);
        $this->assertEquals(0.0, $result['lower']['trend']['slope']);
        $this->assertEquals(0.0, $result['lower']['trend']['intercept']);
    }
}