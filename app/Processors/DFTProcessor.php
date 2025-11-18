<?php
declare(strict_types=1);

namespace App\Processors;

use App\Interfaces\DFTProcessorInterface;
use App\Interfaces\LoggerInterface;
use App\Utilities\Logger;
use App\Processors\NUDiscreteFourierTransformer;

class DFTProcessor implements DFTProcessorInterface {
    private array $config;
    private LoggerInterface $logger;
    private NUDiscreteFourierTransformer $fourierTransformer;

    public function __construct(array $config, Logger $logger) {
        if (!isset($config['corridor_params']['step']) || !isset($config['corridor_params']['max_harmonics']) || !isset($config['corridor_params']['use_common_trend'])) {
            throw new \InvalidArgumentException('Config must contain corridor_params with step, max_harmonics, and use_common_trend');
        }
        $this->config = $config;
        $this->logger = $logger;
        $this->fourierTransformer = new NUDiscreteFourierTransformer($logger);
    }

    /**
     * Генерирует DFT (дискретное преобразование Фурье) для верхней и нижней границ коридора.
     *
     * @param array $bounds Массив границ: ['upper' => [['time' => int, 'value' => float], ...], 'lower' => [['time' => int, 'value' => float], ...]]
     * @param int $start Начальное время диапазона
     * @param int $end Конечное время диапазона
     * @param int $step Шаг времени
     * @return array Результат DFT: ['upper' => ['coefficients' => array, 'trend' => ['slope' => float, 'intercept' => float]], 'lower' => [...]]
     * @throws \InvalidArgumentException Если bounds некорректны (возвращает пустые коэффициенты для пустых bounds)
     */
     public function generateDFT(array $bounds, int $start, int $end, int $step): array {
         if (empty($bounds['upper']) || empty($bounds['lower'])) {
             $this->logger->warning('Bounds are empty, returning empty DFT coefficients');
             return [
                 'upper' => [
                     'coefficients' => [],
                     'trend' => ['slope' => 0, 'intercept' => 0]
                 ],
                 'lower' => [
                     'coefficients' => [],
                     'trend' => ['slope' => 0, 'intercept' => 0]
                 ]
             ];
         }
        $upperValues = array_column($bounds['upper'], 'value');
        $lowerValues = array_column($bounds['lower'], 'value');
        $times = array_column($bounds['upper'], 'time');

        $maxHarmonics = $this->config['corridor_params']['max_harmonics'] ?? 10;
        $totalDuration = $end - $start;
        if ($totalDuration <= 0) {
            $this->logger->warning("Zero or negative total duration: start=$start, end=$end, returning empty coefficients");
            return [
                'upper' => [
                    'coefficients' => [],
                    'trend' => ['slope' => 0, 'intercept' => 0]
                ],
                'lower' => [
                    'coefficients' => [],
                    'trend' => ['slope' => 0, 'intercept' => 0]
                ]
            ];
        }
        $numPoints = count($upperValues);

        // Вычисляем линейный тренд для верхней и нижней границы
        $upperTrend = $this->calculateLinearTrend($upperValues, $times);
        $lowerTrend = $this->calculateLinearTrend($lowerValues, $times);

        // Используем средний тренд, если включен флаг use_common_trend
        $useCommonTrend = $this->config['corridor_params']['use_common_trend'] ?? false;
        if ($useCommonTrend) {
            $commonSlope = ($upperTrend['slope'] + $lowerTrend['slope']) / 2;
            // Корректируем intercept для сохранения индивидуальных значений границ
            $upperTrend['slope'] = $commonSlope;
            $lowerTrend['slope'] = $commonSlope;
            // Пересчитываем intercept, чтобы сохранить средние значения границ
            $upperMean = array_sum($upperValues) / $numPoints;
            $lowerMean = array_sum($lowerValues) / $numPoints;
            $meanTime = array_sum($times) / $numPoints;
            $upperTrend['intercept'] = $upperMean - $commonSlope * $meanTime;
            $lowerTrend['intercept'] = $lowerMean - $commonSlope * $meanTime;
            $this->logger->info("Использован общий средний тренд: slope=$commonSlope, upper_intercept={$upperTrend['intercept']}, lower_intercept={$lowerTrend['intercept']}");
        }

        // Нормализуем данные, вычитая тренд
        $normalizedUpper = $this->normalizeData($upperValues, $times, $upperTrend);
        $normalizedLower = $this->normalizeData($lowerValues, $times, $lowerTrend);

        $upperCoefficients = $this->fourierTransformer->calculateDFT($normalizedUpper, $times, $maxHarmonics, $totalDuration, $numPoints);
        $lowerCoefficients = $this->fourierTransformer->calculateDFT($normalizedLower, $times, $maxHarmonics, $totalDuration, $numPoints);
        $this->logger->info("DFT calculated: upper coefficients=" . count($upperCoefficients) . ", lower coefficients=" . count($lowerCoefficients));

        return [
            'upper' => [
                'coefficients' => $upperCoefficients,
                'trend' => $upperTrend
            ],
            'lower' => [
                'coefficients' => $lowerCoefficients,
                'trend' => $lowerTrend
            ]
        ];
    }

    private function calculateLinearTrend(array $values, array $times): array {
        $n = count($values);
        if ($n < 1) {
            $this->logger->warning("Недостаточно данных для вычисления тренда: $n точек");
            return ['slope' => 0, 'intercept' => 0];
        }
        if ($n == 1) {
            $this->logger->warning("Одна точка данных, тренд - горизонтальная линия на уровне значения");
            return ['slope' => 0, 'intercept' => $values[0]];
        }

        try {
            // Вычисляем линейную регрессию без intercept (intercept = 0)
            $sumXY = 0;
            $sumXX = 0;

            for ($i = 0; $i < $n; $i++) {
                $sumXY += $times[$i] * $values[$i];
                $sumXX += $times[$i] * $times[$i];
            }

            if (abs($sumXX) < 1e-10) {
                $this->logger->warning("Нулевой или почти нулевой знаменатель при вычислении тренда");
                return ['slope' => 0, 'intercept' => 0];
            }

            $slope = $sumXY / $sumXX;
            $intercept = 0;

            $this->logger->debug("Вычислен тренд: slope=$slope, intercept=$intercept");
            return ['slope' => $slope, 'intercept' => $intercept];
        } catch (\Throwable $e) {
            $this->logger->error("Error calculating linear trend: " . $e->getMessage(), __FILE__, __LINE__);
            return ['slope' => 0, 'intercept' => 0];
        }
    }

    private function normalizeData(array $values, array $times, array $trend): array {
        $normalized = [];
        foreach ($values as $i => $value) {
            $trendValue = $trend['slope'] * $times[$i] + $trend['intercept'];
            $normalized[] = $value - $trendValue;
        }
        return $normalized;
    }

    /**
     * Восстанавливает полную DFT-траекторию на основе коэффициентов и тренда.
     *
     * @param array $coefficients Массив коэффициентов DFT: [['amplitude' => float, 'phase' => float], ...]
     * @param int $start Начальное время восстановления
     * @param int $end Конечное время восстановления
     * @param int $step Шаг времени
     * @param array $meta Метаданные: ['dataStart' => int, 'totalDuration' => int, ...]
     * @param array|null $trend Тренд: ['slope' => float, 'intercept' => float]
     * @return array Восстановленные точки: [['time' => int, 'value' => float], ...]
     * @throws \InvalidArgumentException Если meta некорректны или coefficients пусты
     */
    public function restoreFullDFT(array $coefficients, int $start, int $end, int $step, array $meta, ?array $trend = null): array {
        if (empty($coefficients)) {
            $this->logger->info('Coefficients array is empty in restoreFullDFT, proceeding with trend only');
        }
        if (!isset($meta['dataStart']) || !isset($meta['totalDuration'])) {
            throw new \InvalidArgumentException('Meta must contain dataStart and totalDuration');
        }
        $dataStart = $meta['dataStart'] ?? $start;
        $totalDuration = $meta['totalDuration'] ?? ($end - $start);
        $restored = [];
        $periodSeconds = $totalDuration;

        // Восстанавливаем гармоники для всего запрошенного периода
        for ($t = $start; $t <= $end; $t += $step) {
            // Нормализуем время относительно периода данных, чтобы гармоники продолжались
            if ($periodSeconds <= 0) {
                $normalizedTime = 0.0;
            } else {
                $normalizedTime = ($t - $dataStart) / $periodSeconds;
            }
            $value = $this->fourierTransformer->calculateDFTValue($coefficients, $normalizedTime, $periodSeconds);

            // Добавляем тренд, если он предоставлен
            if ($trend !== null) {
                $trendValue = $trend['slope'] * $t + $trend['intercept'];
                $value += $trendValue;
            }

            $restored[] = [
                'time' => $t,
                'value' => $value
            ];
        }

        return $restored;
    }
}