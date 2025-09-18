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
}