<?php
declare(strict_types=1);

namespace App\Processors;

use App\Interfaces\AnomalyDetectorInterface;
use App\Interfaces\LoggerInterface;


class AnomalyDetector implements AnomalyDetectorInterface {
    private array $config;
    private LoggerInterface $logger;

    public function __construct(array $config, LoggerInterface $logger) {
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

        // Сортируем все массивы по времени
        usort($dataPoints, fn($a, $b) => $a['time'] <=> $b['time']);
        usort($upperBound, fn($a, $b) => $a['time'] <=> $b['time']);
        usort($lowerBound, fn($a, $b) => $b['time'] <=> $b['time']);

        $totalDuration = ($dataPoints[array_key_last($dataPoints)]['time'] - $dataPoints[0]['time']);
        if ($totalDuration <= 0) $totalDuration = 1; // Избежать деления на 0

        $step = $this->config['corrdor_params']['step'] ?? 60; // Шаг из config

        // Инициализация для above и below
        $aboveAnomalies = ['points' => [], 'segments' => [], 'total_time' => 0, 'count' => 0];
        $belowAnomalies = ['points' => [], 'segments' => [], 'total_time' => 0, 'count' => 0];

        // Align по индексу, предполагая одинаковый step и aligned times
        $n = count($dataPoints);
        for ($i = 0; $i < $n; $i++) {
            $data = $dataPoints[$i];
            $upper = $upperBound[$i] ?? ['value' => 0];
            $lower = $lowerBound[$i] ?? ['value' => 0];

            $deviation = null;
            if ($data['value'] > $upper['value']) {
                $deviation = $data['value'] - $upper['value'];
                $aboveAnomalies['points'][] = ['time' => $data['time'], 'size' => $deviation];
                $aboveAnomalies['total_time'] += $step;
                $aboveAnomalies['count']++;
            } elseif ($data['value'] < $lower['value']) {
                $deviation = $lower['value'] - $data['value'];
                $belowAnomalies['points'][] = ['time' => $data['time'], 'size' => $deviation];
                $belowAnomalies['total_time'] += $step;
                $belowAnomalies['count']++;
            }
        }

        // Группировка сегментов аномалий (consecutive)
        $above['durations'] = $this->groupAnomalySegments($aboveAnomalies['points']);
        $below['durations'] = $this->groupAnomalySegments($belowAnomalies['points']);
        $above['sizes'] = array_column($aboveAnomalies['points'], 'size');
        $below['sizes'] = array_column($belowAnomalies['points'], 'size');

        // % времени вне коридора
        $above['time_outside_percent'] = round(($aboveAnomalies['total_time'] / $totalDuration) * 100, 2);
        $below['time_outside_percent'] = round(($belowAnomalies['total_time'] / $totalDuration) * 100, 2);
        $above['anomaly_count'] = $aboveAnomalies['count'];
        $below['anomaly_count'] = $belowAnomalies['count'];
        $above['direction'] = 'above';
        $below['direction'] = 'below';

        $combined['time_outside_percent'] = round(($above['time_outside_percent'] + $below['time_outside_percent']) / 2, 2);
        $combined['anomaly_count'] = $above['anomaly_count'] + $below['anomaly_count'];

        if ($raw) {
            $percentiles = $percentileConfig ?? [75];
            $above['durations'] = $this->calculatePercentileValues($above['durations'], $percentiles);
            $below['sizes'] = $this->calculatePercentileValues($below['sizes'], $percentiles);
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
        $currentPercent = $currentStats['time_outside_percent'] ?? 0;
        $historicalPercent = $historicalStats['time_outside_percent'] ?? 0;
        return abs($currentPercent - $historicalPercent);
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
        return $metric * $windowSize; // Теперь % * size, но можно скорректировать если нужно
    }

    /**
     * Группирует consecutive аномалии в сегменты, возвращает durations в секундах
     */
    private function groupAnomalySegments(array $anomalyPoints): array
    {
        if (empty($anomalyPoints)) return [];
        usort($anomalyPoints, fn($a, $b) => $a['time'] <=> $b['time']);
        $durations = [];
        $currentSegmentStart = $anomalyPoints[0]['time'];
        $prevTime = $currentSegmentStart;
        for ($i = 1; $i < count($anomalyPoints); $i++) {
            $currTime = $anomalyPoints[$i]['time'];
            if ($currTime > $prevTime + ($this->config['corrdor_params']['step'] ?? 60)) {
                // Конец сегмента
                $durations[] = ($prevTime - $currentSegmentStart);
                $currentSegmentStart = $currTime;
            }
            $prevTime = $currTime;
        }
        // Последний сегмент
        $durations[] = ($prevTime - $currentSegmentStart);
        return $durations;
    }
}
?>
