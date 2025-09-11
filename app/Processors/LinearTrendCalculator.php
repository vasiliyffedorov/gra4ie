<?php
declare(strict_types=1);

namespace App\Processors;

use App\Interfaces\TrendCalculatorInterface;
use App\Interfaces\LoggerInterface;

class LinearTrendCalculator implements TrendCalculatorInterface
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function calculateTrend(array $values, array $times): array
    {
        $n = count($values);
        if ($n < 2) {
            $this->logger->warn("Недостаточно данных для вычисления тренда: $n точек");
            return ['slope' => 0, 'intercept' => $values[0] ?? 0];
        }

        $sumX = array_sum($times);
        $sumY = array_sum($values);
        $sumXY = 0;
        $sumXX = 0;

        for ($i = 0; $i < $n; $i++) {
            $sumXY += $times[$i] * $values[$i];
            $sumXX += $times[$i] * $times[$i];
        }

        $meanX = $sumX / $n;
        $meanY = $sumY / $n;
        $denominator = $sumXX - $n * $meanX * $meanX;

        if (abs($denominator) < 1e-10) {
            $this->logger->warn("Нулевой или почти нулевой знаменатель при вычислении тренда");
            return ['slope' => 0, 'intercept' => $meanY];
        }

        $slope = ($sumXY - $n * $meanX * $meanY) / $denominator;
        $intercept = $meanY - $slope * $meanX;

        $this->logger->info("Вычислен тренд: slope=$slope, intercept=$intercept");
        return ['slope' => $slope, 'intercept' => $intercept];
    }
}