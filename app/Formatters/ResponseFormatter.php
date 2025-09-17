<?php
declare(strict_types=1);

namespace App\Formatters;

use App\Interfaces\LoggerInterface;
use App\Interfaces\AnomalyDetectorInterface;

class ResponseFormatter {
    private $showMetrics;
    private $config;
    private $anomalyDetector;
    private LoggerInterface $logger;

    /**
     * @param array $config Конфигурация
     * @param LoggerInterface $logger Логгер
     * @param AnomalyDetectorInterface $anomalyDetector Детектор аномалий
     */
    public function __construct(array $config, LoggerInterface $logger, AnomalyDetectorInterface $anomalyDetector) {
        if (!isset($config['log_file'])) {
            throw new \InvalidArgumentException('Config must contain log_file');
        }
        $this->config = $config;
        $this->logger = $logger;
        $this->anomalyDetector = $anomalyDetector;
        $this->updateConfig($config);
    }

    private function getLogLevel(string $logLevel): int {
        $logLevelMap = [
            'INFO' => \App\Utilities\Logger::LEVEL_INFO,
            'WARN' => \App\Utilities\Logger::LEVEL_WARN,
            'ERROR' => \App\Utilities\Logger::LEVEL_ERROR
        ];
        return $logLevelMap[strtoupper($logLevel)] ?? \App\Utilities\Logger::LEVEL_INFO;
    }

    /**
     * Обновляет конфигурацию форматтера.
     *
     * @param array $config Конфигурация: ['dashboard' => ['show_metrics' => array|string], ...]
     * @throws \InvalidArgumentException Если show_metrics некорректен
     */
    public function updateConfig(array $config): void {
        $this->config = $config;
        $showMetrics = $config['dashboard']['show_metrics'] ?? [
            'original',
            'nodata',
            'dft_upper',
            'dft_lower',
            'dft_range',
            'anomaly_stats',
            'anomaly_concern',
            'anomaly_concern_sum',
            'historical_metrics'
        ];
        if (!is_array($showMetrics) && !is_string($showMetrics)) {
            throw new \InvalidArgumentException('show_metrics must be array or string');
        }
        $this->showMetrics = $showMetrics;
    }

    /**
     * Форматирует результаты для Grafana в формате matrix.
     *
     * @param array $results Массив результатов: [['labels' => array, 'original' => array, 'dft_upper' => array, ...], ...]
     * @param string $query Оригинальный запрос
     * @param string|array|null $filter Фильтр метрик (строка через запятую или массив; если null, использует config)
     * @return array Форматированный ответ: ['status' => string, 'data' => ['resultType' => 'matrix', 'result' => array]]
     * @throws \RuntimeException Если конфигурация метрик некорректна
     */
    public function formatForGrafana(array $results, string $query, string|array|null $filter = null): array {
        if (empty($query)) {
            $this->logger->error("Empty query provided to formatForGrafana");
            return ['status' => 'error', 'data' => ['message' => 'Invalid query']];
        }
        if (empty($results)) {
            $this->logger->warning("Empty results for query '$query', returning empty matrix with nodata");
            return [
                'status' => 'success',
                'data' => [
                    'resultType' => 'matrix',
                    'result' => [
                        [
                            'metric' => ['__name__' => 'nodata', 'original_query' => $query],
                            'values' => [[time(), '1']] // Flag nodata
                        ]
                    ]
                ]
            ];
        }
        $formatted = [
            'status' => 'success',
            'data' => [
                'resultType' => 'matrix',
                'result' => []
            ]
        ];

        $metricsToShow = $filter ?? $this->showMetrics;
        if (is_string($metricsToShow)) {
            $trimmed = array_map('trim', explode(',', $metricsToShow));
            $metricsToShow = array_filter($trimmed); // Удаляем пустые
            if (empty($metricsToShow)) {
                $metricsToShow = [$metricsToShow];
            }
        }

        if (!is_array($metricsToShow)) {
            throw new \RuntimeException("Invalid metrics filter: expected array, got " . gettype($metricsToShow));
        }

        foreach ($results as $result) {
            $labels = $result['labels'] ?? [];
            $percentileConfig = $this->config['corrdor_params']['default_percentiles'] ?? ['duration' => 75, 'size' => 75];

            if (in_array('original', $metricsToShow) && isset($result['original'])) {
                $this->addMetric($formatted, $labels, $query, 'original', $result['original'] ?? []);
            }

            if (in_array('nodata', $metricsToShow) && isset($result['nodata'])) {
                $this->addMetric($formatted, $labels, $query, 'nodata', $result['nodata'] ?? []);
            }

            if (in_array('dft_upper', $metricsToShow)) {
                $this->addMetric($formatted, $labels, $query, 'dft_upper', $result['dft_upper'] ?? []);
            }

            if (in_array('dft_lower', $metricsToShow)) {
                $this->addMetric($formatted, $labels, $query, 'dft_lower', $result['dft_lower'] ?? []);
            }

            if (in_array('dft_range', $metricsToShow) && isset($result['dft_upper']) && isset($result['dft_lower'])) {
                $range = [];
                foreach ($result['dft_upper'] as $i => $upperPoint) {
                    $lowerPoint = $result['dft_lower'][$i] ?? ['value' => 0];
                    $range[] = [
                        'time' => $upperPoint['time'],
                        'value' => $upperPoint['value'] - $lowerPoint['value']
                    ];
                }
                $this->addMetric($formatted, $labels, $query, 'dft_range', $range);
            }

            $currentStats = $result['current_anomaly_stats'] ?? [];
            $historicalStats = $result['historical_anomaly_stats'] ?? [];

            if (in_array('anomaly_stats', $metricsToShow)) {
                $this->addDirectionalMetrics($formatted, $labels, $query, 'above', $currentStats, $historicalStats, $percentileConfig);
                $this->addDirectionalMetrics($formatted, $labels, $query, 'below', $currentStats, $historicalStats, $percentileConfig);
                $this->addCombinedMetrics($formatted, $labels, $query, $currentStats, $historicalStats);
            }

            if (in_array('anomaly_concern', $metricsToShow)) {
                $formatted['data']['result'][] = [
                    'metric' => $this->formatMetricLabels(array_merge($labels, ['__name__' => 'anomaly_concern_above', 'original_query' => $query])),
                    'values' => [[time(), (string)(($result['anomaly_concern_above'] ?? 0) * 100)]]
                ];
                $formatted['data']['result'][] = [
                    'metric' => $this->formatMetricLabels(array_merge($labels, ['__name__' => 'anomaly_concern_below', 'original_query' => $query])),
                    'values' => [[time(), (string)(($result['anomaly_concern_below'] ?? 0) * 100)]]
                ];
            }

            if (in_array('anomaly_concern_sum', $metricsToShow)) {
                $aboveS = $result['anomaly_concern_duration_sum_above'] ?? 0;
                $belowS = $result['anomaly_concern_duration_sum_below'] ?? 0;
                $aboveS += $result['anomaly_concern_size_sum_above'] ?? 0;
                $belowS += $result['anomaly_concern_size_sum_below'] ?? 0;
                $formatted['data']['result'][] = [
                    'metric' => $this->formatMetricLabels(array_merge($labels, ['__name__' => 'anomaly_concern_above_sum', 'original_query' => $query])),
                    'values' => [[time(), (string)round($aboveS * 100, 2)]]
                ];
                $formatted['data']['result'][] = [
                    'metric' => $this->formatMetricLabels(array_merge($labels, ['__name__' => 'anomaly_concern_below_sum', 'original_query' => $query])),
                    'values' => [[time(), (string)round($belowS * 100, 2)]]
                ];
                // Separate sum concerns
                $formatted['data']['result'][] = [
                    'metric' => $this->formatMetricLabels(array_merge($labels, ['__name__' => 'anomaly_concern_duration_sum_above', 'original_query' => $query])),
                    'values' => [[time(), (string)round(($result['anomaly_concern_duration_sum_above'] ?? 0) * 100, 2)]]
                ];
                $formatted['data']['result'][] = [
                    'metric' => $this->formatMetricLabels(array_merge($labels, ['__name__' => 'anomaly_concern_size_sum_above', 'original_query' => $query])),
                    'values' => [[time(), (string)round(($result['anomaly_concern_size_sum_above'] ?? 0) * 100, 2)]]
                ];
                $formatted['data']['result'][] = [
                    'metric' => $this->formatMetricLabels(array_merge($labels, ['__name__' => 'anomaly_concern_duration_sum_below', 'original_query' => $query])),
                    'values' => [[time(), (string)round(($result['anomaly_concern_duration_sum_below'] ?? 0) * 100, 2)]]
                ];
                $formatted['data']['result'][] = [
                    'metric' => $this->formatMetricLabels(array_merge($labels, ['__name__' => 'anomaly_concern_size_sum_below', 'original_query' => $query])),
                    'values' => [[time(), (string)round(($result['anomaly_concern_size_sum_below'] ?? 0) * 100, 2)]]
                ];
            }

            if (in_array('historical_metrics', $metricsToShow) && !empty($historicalStats)) {
                $this->addHistoricalMetrics($formatted, $labels, $query, $historicalStats, $percentileConfig);
            }

            if (in_array('dft_rebuild_count', $metricsToShow) && isset($result['dft_rebuild_count'])) {
                $formatted['data']['result'][] = [
                    'metric' => $this->formatMetricLabels(array_merge($labels, ['__name__' => 'dft_rebuild_count', 'original_query' => $query])),
                    'values' => [[time(), (string)$result['dft_rebuild_count']]]
                ];
            }
        }

        return $formatted;
    }

    private function formatMetricLabels(array $labels): array {
        $formatted = [];
        // Ensure __name__ is the first key
        if (isset($labels['__name__'])) {
            $formatted['__name__'] = $labels['__name__'];
            unset($labels['__name__']);
        }
        // Add remaining labels in sorted order
        ksort($labels);
        return array_merge($formatted, $labels);
    }

    private function addMetric(array &$formatted, array $labels, string $query, string $name, array $data): void {
        $formatted['data']['result'][] = [
            'metric' => $this->formatMetricLabels(array_merge($labels, ['__name__' => $name, 'original_query' => $query])),
            'values' => array_map(
                fn($p) => [(int)($p['time'] ?? 0), (string)round($p['value'] ?? 0, 6)],
                $data
            )
        ];
    }

    private function addDirectionalMetrics(array &$formatted, array $labels, string $query, string $direction, array $currentStats, array $historicalStats, array $percentileConfig): void {
        $prefix = ($direction === 'above') ? 'upper_' : 'lower_';
        $current = $currentStats[$direction] ?? [];
        $historical = $historicalStats[$direction] ?? [];

        $historicalDurationPercentile = $this->anomalyDetector->calculatePercentile(
            $historical['durations'] ?? [],
            $percentileConfig['duration'] ?? 75
        );
        $historicalSizePercentile = $this->anomalyDetector->calculatePercentile(
            $historical['sizes'] ?? [],
            $percentileConfig['size'] ?? 75
        );

        $metrics = [
            'time_outside_percent' => $current['time_outside_percent'] ?? 0,
            'anomaly_count' => $current['anomaly_count'] ?? 0,
            'anomaly_duration' => !empty($current['durations']) ? max($current['durations']) : 0,
            'anomaly_size' => !empty($current['sizes']) ? max($current['sizes']) : 0
        ];

        foreach ($metrics as $metricName => $metricValue) {
            $formatted['data']['result'][] = [
                'metric' => $this->formatMetricLabels(array_merge($labels, [
                    '__name__' => $prefix . $metricName,
                    'original_query' => $query,
                    'direction' => $direction
                ])),
                'values' => [[time(), (string)$metricValue]]
            ];
        }

        $historicalMetrics = [
            'historical_anomaly_duration' => $historicalDurationPercentile,
            'historical_anomaly_size' => $historicalSizePercentile
        ];

        foreach ($historicalMetrics as $metricName => $metricValue) {
            $formatted['data']['result'][] = [
                'metric' => $this->formatMetricLabels(array_merge($labels, [
                    '__name__' => $prefix . $metricName,
                    'original_query' => $query,
                    'direction' => $direction
                ])),
                'values' => [[time(), (string)$metricValue]]
            ];
        }
    }

    private function addCombinedMetrics(array &$formatted, array $labels, string $query, array $currentStats, array $historicalStats): void {
        $combined = $currentStats['combined'] ?? [];
        $combinedMetrics = [
            'combined_time_outside_percent' => $combined['time_outside_percent'] ?? 0,
            'combined_anomaly_count' => $combined['anomaly_count'] ?? 0
        ];

        foreach ($combinedMetrics as $metricName => $metricValue) {
            $formatted['data']['result'][] = [
                'metric' => $this->formatMetricLabels(array_merge($labels, [
                    '__name__' => $metricName,
                    'original_query' => $query
                ])),
                'values' => [[time(), (string)$metricValue]]
            ];
        }
    }

    private function addHistoricalMetrics(array &$formatted, array $labels, string $query, array $historicalStats, array $percentileConfig): void {
        foreach (['above', 'below'] as $direction) {
            if (isset($historicalStats[$direction])) {
                $stats = $historicalStats[$direction];
                $prefix = ($direction === 'above') ? 'historical_upper_' : 'historical_lower_';

                $historicalDurationPercentile = $this->anomalyDetector->calculatePercentile(
                    $stats['durations'] ?? [],
                    $percentileConfig['duration'] ?? 75
                );
                $historicalSizePercentile = $this->anomalyDetector->calculatePercentile(
                    $stats['sizes'] ?? [],
                    $percentileConfig['size'] ?? 75
                );

                $metrics = [
                    'time_outside_percent' => $stats['time_outside_percent'] ?? 0,
                    'anomaly_count' => $stats['anomaly_count'] ?? 0,
                    'anomaly_duration' => !empty($stats['durations']) ? max($stats['durations']) : 0,
                    'anomaly_size' => !empty($stats['sizes']) ? max($stats['sizes']) : 0,
                    'historical_anomaly_duration' => $historicalDurationPercentile,
                    'historical_anomaly_size' => $historicalSizePercentile
                ];

                foreach ($metrics as $metricName => $metricValue) {
                    $formatted['data']['result'][] = [
                        'metric' => $this->formatMetricLabels(array_merge($labels, [
                            '__name__' => $prefix . $metricName,
                            'original_query' => $query,
                            'direction' => $direction
                        ])),
                        'values' => [[time(), (string)$metricValue]]
                    ];
                }
            }
        }
    }

    /**
     * Вычисляет placeholder для верхней и нижней границ при недостатке данных.
     *
     * @param array $data Исторические данные (не используются для placeholder)
     * @param int $start Начальное время
     * @param int $end Конечное время
     * @param int $step Шаг времени
     * @return array Границы: ['upper' => [['time' => int, 'value' => float], ...], 'lower' => [...]]
     * @throws \InvalidArgumentException Если step <= 0 или start > end
     */
    public function calculateBounds(array $data, int $start, int $end, int $step): array {
        if ($step <= 0 || $start > $end) {
            throw new \InvalidArgumentException('Step must be positive and start <= end');
        }
        $upper = [];
        $lower = [];
        for ($t = $start; $t <= $end; $t += $step) {
            $upper[] = ['time' => $t, 'value' => 0];
            $lower[] = ['time' => $t, 'value' => 0];
        }
        return ['upper' => $upper, 'lower' => $lower];
    }

    /**
     * Ресэмплирует DFT-данные с интерполяцией на новый шаг.
     *
     * @param array $dftData Оригинальные DFT-точки: [['time' => int, 'value' => float], ...]
     * @param int $start Новое начальное время
     * @param int $end Новое конечное время
     * @param int $step Новый шаг времени
     * @return array Ресэмплированные точки: [['time' => int, 'value' => float], ...]
     * @throws \InvalidArgumentException Если step <= 0 или dftData пусты
     */
    public function resampleDFT(array $dftData, int $start, int $end, int $step): array {
        if (empty($dftData) || $step <= 0 || $start > $end) {
            throw new \InvalidArgumentException('dftData must not be empty, step > 0, start <= end');
        }
        $resampled = [];
        for ($currentTime = $start; $currentTime <= $end; $currentTime += $step) {
            $resampled[] = [
                'time' => $currentTime,
                'value' => $this->interpolateDFT($dftData, $currentTime)
            ];
        }
        return $resampled;
    }

    private function interpolateDFT(array $dftData, int $targetTime): float {
        $left = $right = null;
        foreach ($dftData as $point) {
            if (!isset($point['time'])) continue;
            if ($point['time'] <= $targetTime) {
                $left = $point;
            } elseif ($right === null || $point['time'] < $right['time']) {
                $right = $point;
            }
        }
        if (!$left && !$right) return 0;
        if (!$left) return $right['value'] ?? 0;
        if (!$right) return $left['value'] ?? 0;
        $deltaTime = $right['time'] - $left['time'];
        return $deltaTime == 0 ? $left['value'] ?? 0 : ($left['value'] ?? 0) + (($right['value'] ?? 0) - ($left['value'] ?? 0)) * ($targetTime - $left['time']) / $deltaTime;
    }
}
?>