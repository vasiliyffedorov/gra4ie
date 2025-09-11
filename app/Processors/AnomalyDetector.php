<?php
declare(strict_types=1);

namespace App\Processors;

use App\Utilities\Logger;

class AnomalyDetector {
    private $config;
    private $logger;

    public function __construct(array $config, Logger $logger) {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function updateConfig(array $config): void {
        $this->config = $config;
    }

    /**
     * Рассчитывает статистику аномалий.
     *
     * @param array      $dataPoints        Сырые точки [ ['time'=>int, 'value'=>float], ... ]
     * @param array      $upperBound        Коридор сверху
     * @param array      $lowerBound        Коридор снизу
     * @param array|null $percentileConfig  Конфиг перцентилей (только для истории)
     * @param bool       $raw               Если true — вернуть сырые массивы длительностей/размеров
     *
     * @return array{above: array, below: array, combined: array}
     */
    public function calculateAnomalyStats(
        array $dataPoints,
        array $upperBound,
        array $lowerBound,
        ?array $percentileConfig = null,
        bool $raw = false
    ): array {
        if (empty($dataPoints) || empty($upperBound) || empty($lowerBound)) {
            $this->logger->warn(
                "Недостаточно данных для расчёта статистики аномалий: "
                . "dataPoints=" . count($dataPoints)
                . ", upperBound=" . count($upperBound)
                . ", lowerBound=" . count($lowerBound),
                __FILE__, __LINE__
            );
            $zeroStats = [
                'time_outside_percent' => 0,
                'anomaly_count'        => 0,
                'durations'            => [],
                'sizes'                => [],
                'direction'            => ''
            ];
            return [
                'above'    => array_merge($zeroStats, ['direction'=>'above']),
                'below'    => array_merge($zeroStats, ['direction'=>'below']),
                'combined' => [
                    'time_outside_percent' => 0,
                    'anomaly_count'        => 0
                ]
            ];
        }

        usort($dataPoints, fn($a, $b) => $a['time'] <=> $b['time']);

        $start = $dataPoints[0]['time'] ?? 0;

        $above = [];
        $below = [];
        $combined = [];

        $percentiles = $percentileConfig ?? ['duration' => 75, 'size' => 75];
        $cntP = count($percentiles);

        if (count($dataPoints) <= $cntP) {
            $durations = array_map(fn($p) => $p['time'] - $start, $dataPoints);
            $sizes = array_map(fn($p) => $p['value'], $dataPoints);
        } else {
            $durations = $this->calculatePercentileValues(
                array_map(fn($p) => $p['time'] - $start, $dataPoints),
                $percentiles
            );
            $sizes = $this->calculatePercentileValues(
                array_map(fn($p) => $p['value'], $dataPoints),
                $percentiles
            );
        }

        $above['time_outside_percent'] = round($durations[$cntP - 1] ?? 0, 2);
        $below['time_outside_percent'] = round($durations[$cntP - 1] ?? 0, 2);
        $above['anomaly_count'] = count($durations);
        $below['anomaly_count'] = count($sizes);
        $above['durations'] = $durations;
        $below['sizes'] = $sizes;
        $above['direction'] = 'above';
        $below['direction'] = 'below';

        $combined['time_outside_percent'] = round(($above['time_outside_percent'] + $below['time_outside_percent']) / 2, 2);
        $combined['anomaly_count'] = $above['anomaly_count'] + $below['anomaly_count'];

        if ($raw) {
            return [
                'above' => $above,
                'below' => $below,
                'combined' => $combined
            ];
        }

        return [
            'above' => [
                'time_outside_percent' => $above['time_outside_percent'],
                'anomaly_count' => $above['anomaly_count']
            ],
            'below' => [
                'time_outside_percent' => $below['time_outside_percent'],
                'anomaly_count' => $below['anomaly_count']
            ],
            'combined' => $combined
        ];
    }

    /**
     * Рассчитывает значения перцентилей для массива значений.
     *
     * @param array $values Массив значений
     * @param array $percentiles Массив перцентилей (например, [75])
     * @return array Массив рассчитанных перцентильных значений в порядке перцентилей
     */
    private function calculatePercentileValues(array $values, array $percentiles): array
    {
        if (empty($values)) {
            return [];
        }

        sort($values); // Сортируем значения по возрастанию
        $n = count($values);
        $results = [];

        foreach ($percentiles as $p) {
            $percent = $p / 100;
            $index = ($n - 1) * $percent;
            $lowerIndex = floor($index);
            $upperIndex = ceil($index);

            if ($lowerIndex === $upperIndex) {
                $results[] = $values[$lowerIndex];
            } else {
                // Линейная интерполяция
                $fraction = $index - $lowerIndex;
                $percentileValue = $values[$lowerIndex] + $fraction * ($values[$upperIndex] - $values[$lowerIndex]);
                $results[] = $percentileValue;
            }
        }

        return $results;
    }

    /**
     * Рассчитывает интегральную метрику для аномалий.
     *
     * @param array $currentStats Текущие статистики аномалий (durations или sizes)
     * @param array $historicalStats Исторические статистики аномалий
     * @return float Интегральная метрика (сумма отклонений)
     */
    public function calculateIntegralMetric(array $currentStats, array $historicalStats): float
    {
        $current = array_sum($currentStats['durations'] ?? $currentStats['sizes'] ?? []);
        $historical = array_sum($historicalStats['durations'] ?? $historicalStats['sizes'] ?? []);
        return abs($current - $historical);
    }

    /**
     * Рассчитывает сумму интегральной метрики с учетом размера окна.
     *
     * @param array $currentStats Текущие статистики аномалий
     * @param array $historicalStats Исторические статистики аномалий
     * @param int $windowSize Размер окна
     * @return float Сумма интегральной метрики
     */
    public function calculateIntegralMetricSum(array $currentStats, array $historicalStats, int $windowSize): float
    {
        $metric = $this->calculateIntegralMetric($currentStats, $historicalStats);
        return $metric * $windowSize;
    }
}
?>
