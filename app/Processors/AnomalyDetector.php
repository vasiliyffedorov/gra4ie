<?php
declare(strict_types=1);

namespace App\Processors;

use App\Interfaces\AnomalyDetectorInterface;
use App\Interfaces\LoggerInterface;


class AnomalyDetector implements AnomalyDetectorInterface {
    private array $config;
    private LoggerInterface $logger;

    public function __construct(array $config, LoggerInterface $logger) {
        if (!isset($config['corrdor_params']['step']) || !isset($config['cache']['percentiles'])) {
            throw new \InvalidArgumentException('Config must contain corrdor_params.step and cache.percentiles');
        }
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Обновляет конфигурацию детектора аномалий.
     *
     * @param array $config Новый конфиг: ['corrdor_params' => [...], 'cache' => [...]]
     */
    public function updateConfig(array $config): void {
        $this->config = $config;
    }

    /**
     * Рассчитывает статистику аномалий.
     *
     * @param array $dataPoints Сырые точки данных: [['time' => int, 'value' => float], ...]
     * @param array $upperBound Верхняя граница коридора: [['time' => int, 'value' => float], ...]
     * @param array $lowerBound Нижняя граница коридора: [['time' => int, 'value' => float], ...]
     * @param array|null $percentileConfig Конфигурация перцентилей для исторических данных (опционально)
     * @param bool $raw Если true, возвращает сырые массивы durations/sizes
     * @param bool $isHistorical Если true, сжимает историю по перцентилям
     * @param int $actualStep Реальный шаг времени данных (сек)
     * @return array Статистика: ['above' => ['time_outside_percent' => float, 'anomaly_count' => int, 'durations' => array, 'sizes' => array, 'direction' => string], 'below' => [...], 'combined' => ['time_outside_percent' => float, 'anomaly_count' => int]]
     * @throws \InvalidArgumentException Если входные массивы пусты или несоответствуют по размеру
     */
    public function calculateAnomalyStats(
        array $dataPoints,
        array $upperBound,
        array $lowerBound,
        ?array $percentileConfig = null,
        bool $raw = false,
        bool $isHistorical = false,
        int $actualStep = 60
    ): array {
        if (empty($upperBound) || empty($lowerBound)) {
            throw new \InvalidArgumentException('Upper and lower bounds must not be empty');
        }
        if (empty($dataPoints)) {
            $this->logger->info("No data points provided, returning zero anomaly stats");
            $zeroStats = [
                'time_outside_percent' => 0,
                'anomaly_count'        => 0,
                'durations'            => [],
                'sizes'                => [],
                'direction'            => ''
            ];
            $above = array_merge($zeroStats, ['direction' => 'above']);
            $below = array_merge($zeroStats, ['direction' => 'below']);
            $combined = [
                'time_outside_percent' => 0,
                'anomaly_count'        => 0
            ];
            if ($raw || $isHistorical) {
                return [
                    'above' => $above,
                    'below' => $below,
                    'combined' => $combined
                ];
            } else {
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
        }
        $n = min([count($dataPoints), count($upperBound), count($lowerBound)]);
        $this->logger->warning(
            "Размеры массивов не совпадают, используются первые $n элементов: "
            . "dataPoints=" . count($dataPoints)
            . ", upperBound=" . count($upperBound)
            . ", lowerBound=" . count($lowerBound),
            ['file' => __FILE__, 'line' => __LINE__]
        );
        $zeroStats = [
            'time_outside_percent' => 0,
            'anomaly_count'        => 0,
            'durations'            => [],
            'sizes'                => [],
            'direction'            => ''
        ];
        $above = array_merge($zeroStats, ['direction' => 'above']);
        $below = array_merge($zeroStats, ['direction' => 'below']);
        $combined = [
            'time_outside_percent' => 0,
            'anomaly_count'        => 0
        ];

        // Сортируем все массивы по времени
        usort($dataPoints, fn($a, $b) => $a['time'] <=> $b['time']);
        usort($upperBound, fn($a, $b) => $a['time'] <=> $b['time']);
        usort($lowerBound, fn($a, $b) => $a['time'] <=> $b['time']);

        $totalDuration = ($dataPoints[array_key_last($dataPoints)]['time'] - $dataPoints[0]['time']);
        if ($totalDuration <= 0) $totalDuration = 1; // Избежать деления на 0

        $step = $actualStep; // Используем переданный actualStep

        // Инициализация для above и below
        $aboveAnomalies = ['points' => [], 'segments' => [], 'total_time' => 0, 'count' => 0];
        $belowAnomalies = ['points' => [], 'segments' => [], 'total_time' => 0, 'count' => 0];

        // Align по индексу, предполагая одинаковый step и aligned times
        // Use $n from min(counts) at line 54
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
        $above['durations'] = $this->groupAnomalySegments($aboveAnomalies['points'], $step);
        $below['durations'] = $this->groupAnomalySegments($belowAnomalies['points'], $step);
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

        if ($isHistorical) {
            $above['durations'] = $this->compressHistory($above['durations']);
            $above['sizes'] = $this->compressHistory($above['sizes']);
            $below['durations'] = $this->compressHistory($below['durations']);
            $below['sizes'] = $this->compressHistory($below['sizes']);
            return [
                'above' => $above,
                'below' => $below,
                'combined' => $combined
            ];
        } elseif ($raw) {
            return [
                'above' => $above,
                'below' => $below,
                'combined' => $combined
            ];
        } else {
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
      * Сжимает историю аномалий согласно логике: <=12 - pad нулями до 12; >12 - вычисляет перцентили из cache.percentiles
      */
     private function compressHistory(array $values): array
     {
         $percentilesStr = $this->config['cache']['percentiles'] ?? "0,10,20,30,40,50,60,70,80,90,95,100";
         if (is_array($percentilesStr)) $percentilesStr = implode(',', $percentilesStr);
         $pcts = array_map(fn($s) => (int)trim($s), explode(',', $percentilesStr));
         $numPcts = count($pcts); // 12

         if (count($values) <= $numPcts) {
             sort($values);
             while (count($values) < $numPcts) {
                 $values[] = 0;
             }
             return $values;
         } else {
             sort($values);
             return $this->calculatePercentileValues($values, $pcts);
         }
     }

    /**
     * Рассчитывает интегральную метрику обеспокоенности аномалиями отдельно для длительности и размера.
     *
     * @param array $currentStats Текущие статистики: ['durations' => [int, ...], 'sizes' => [float, ...]]
     * @param array $historicalStats Исторические сжатые статистики: ['durations' => [int, ...], 'sizes' => [float, ...]]
     * @return array Метрики: ['duration_concern' => float, 'size_concern' => float, 'total_concern' => float]
     * @throws \InvalidArgumentException Если статистики пусты
     */
    public function calculateIntegralMetric(array $currentStats, array $historicalStats): array
    {
        if (empty($currentStats) || empty($historicalStats)) {
            throw new \InvalidArgumentException('Current and historical stats must not be empty');
        }
        $durationMult = $this->config['corrdor_params']['default_percentiles']['duration_multiplier'] ?? 1;
        $sizeMult = $this->config['corrdor_params']['default_percentiles']['size_multiplier'] ?? 1;
        $durationP = $this->config['corrdor_params']['default_percentiles']['duration'] ?? 75;
        $sizeP = $this->config['corrdor_params']['default_percentiles']['size'] ?? 75;

        $durConcern = 0.0;
        if (!empty($currentStats['durations'])) {
            $currMaxDur = max($currentStats['durations']);
            if (!empty($historicalStats['durations'])) {
                $durIndex = (int)($durationP / 100 * 11); // for 12 elements
                $histDur = $historicalStats['durations'][$durIndex] ?? 0;
                if ($histDur > 0) {
                    $ratio = $currMaxDur / ($histDur * $durationMult);
                    $durConcern = max(0, $ratio - 1);
                } else {
                    $durConcern = 1.0;
                }
            } else {
                $durConcern = 1.0;
            }
        }

        $sizeConcern = 0.0;
        if (!empty($currentStats['sizes'])) {
            $currMaxSize = max($currentStats['sizes']);
            if (!empty($historicalStats['sizes'])) {
                $sizeIndex = (int)($sizeP / 100 * 11);
                $histSize = $historicalStats['sizes'][$sizeIndex] ?? 0;
                if ($histSize > 0) {
                    $ratio = $currMaxSize / ($histSize * $sizeMult);
                    $sizeConcern = max(0, $ratio - 1);
                } else {
                    $sizeConcern = 1.0;
                }
            } else {
                $sizeConcern = 1.0;
            }
        }

        $totalConcern = $durConcern + $sizeConcern;

        return [
            'duration_concern' => $durConcern,
            'size_concern' => $sizeConcern,
            'total_concern' => $totalConcern
        ];
    }

    /**
     * Рассчитывает сумму интегральной метрики с учетом размера окна, отдельно для длительности и размера.
     *
     * @param array $currentStats Текущие статистики: ['durations' => [int, ...], 'sizes' => [float, ...]]
     * @param array $historicalStats Исторические статистики: ['durations' => [int, ...], 'sizes' => [float, ...]]
     * @param int $windowSize Размер окна для умножения
     * @return array Суммы: ['duration_concern_sum' => float, 'size_concern_sum' => float, 'total_concern_sum' => float]
     * @throws \InvalidArgumentException Если windowSize <= 0 или статистики пусты
     */
    public function calculateIntegralMetricSum(array $currentStats, array $historicalStats, int $windowSize): array
    {
        if ($windowSize <= 0) {
            throw new \InvalidArgumentException('Window size must be positive');
        }
        $concerns = $this->calculateIntegralMetric($currentStats, $historicalStats);
        return [
            'duration_concern_sum' => $concerns['duration_concern'] * $windowSize,
            'size_concern_sum' => $concerns['size_concern'] * $windowSize,
            'total_concern_sum' => $concerns['total_concern'] * $windowSize
        ];
    }

    /**
     * Группирует consecutive аномалии в сегменты, возвращает durations в секундах
     */
    private function groupAnomalySegments(array $anomalyPoints, int $actualStep = 60): array
    {
        if (empty($anomalyPoints)) return [];
        usort($anomalyPoints, fn($a, $b) => $a['time'] <=> $b['time']);
        $durations = [];
        $segmentCount = 1;
        $prevTime = $anomalyPoints[0]['time'];
        for ($i = 1; $i < count($anomalyPoints); $i++) {
            $currTime = $anomalyPoints[$i]['time'];
            if ($currTime > $prevTime + $actualStep) {
                // Конец сегмента
                $durations[] = $segmentCount * $actualStep;
                $segmentCount = 1;
            } else {
                $segmentCount++;
            }
            $prevTime = $currTime;
        }
        // Последний сегмент
        $durations[] = $segmentCount * $actualStep;
        return $durations;
    }
}
?>
