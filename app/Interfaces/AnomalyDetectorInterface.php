<?php
declare(strict_types=1);

namespace App\Interfaces;

interface AnomalyDetectorInterface
{
    public function updateConfig(array $config): void;

    public function calculateAnomalyStats(
        array $dataPoints,
        array $upperBound,
        array $lowerBound,
        ?array $percentileConfig = null,
        bool $raw = false,
        bool $isHistorical = false
    ): array;

    public function calculateIntegralMetric(array $currentStats, array $historicalStats): array;

    public function calculateIntegralMetricSum(array $currentStats, array $historicalStats, int $windowSize): array;
}