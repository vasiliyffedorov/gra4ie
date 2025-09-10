<?php
declare(strict_types=1);

require_once __DIR__ . '/../Interfaces/DFTProcessorInterface.php';
require_once __DIR__ . '/../Utilities/Logger.php';
require_once __DIR__ . '/FourierTransformer.php';

class DFTProcessor implements DFTProcessorInterface {
    private array $config;
    private LoggerInterface $logger;
    private FourierTransformerInterface $fourierTransformer;

    public function __construct(array $config, LoggerInterface $logger) {
        $this->config = $config;
        $this->logger = $logger;
        $this->fourierTransformer = new FourierTransformer($logger);
    }

    public function generateDFT(array $bounds, int $start, int $end, int $step): array {
        $upperValues = array_column($bounds['upper'], 'value');
        $lowerValues = array_column($bounds['lower'], 'value');
        $times = array_column($bounds['upper'], 'time');

        $maxHarmonics = $this->config['corrdor_params']['max_harmonics'] ?? 10;
        $totalDuration = $end - $start;
        $numPoints = count($upperValues);

        // Вычисляем линейный тренд для верхней и нижней границы
        $upperTrend = $this->calculateLinearTrend($upperValues, $times);
        $lowerTrend = $this->calculateLinearTrend($lowerValues, $times);

        // Используем средний тренд, если включен флаг use_common_trend
        $useCommonTrend = $this->config['corrdor_params']['use_common_trend'] ?? false;
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

        $upperCoefficients = $this->fourierTransformer->calculateDFT($normalizedUpper, $maxHarmonics, $totalDuration, $numPoints);
        $lowerCoefficients = $this->fourierTransformer->calculateDFT($normalizedLower, $maxHarmonics, $totalDuration, $numPoints);

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
        if ($n < 2) {
            $this->logger->warn("Недостаточно данных для вычисления тренда: $n точек");
            return ['slope' => 0, 'intercept' => $values[0] ?? 0];
        }

        // Вычисляем линейную регрессию по всем точкам
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

    private function normalizeData(array $values, array $times, array $trend): array {
        $normalized = [];
        foreach ($values as $i => $value) {
            $trendValue = $trend['slope'] * $times[$i] + $trend['intercept'];
            $normalized[] = $value - $trendValue;
        }
        return $normalized;
    }

    public function restoreFullDFT(array $coefficients, int $start, int $end, int $step, array $meta, ?array $trend = null): array {
        $dataStart = $meta['dataStart'] ?? $start;
        $totalDuration = $meta['totalDuration'] ?? ($end - $start);
        $restored = [];
        $periodSeconds = $totalDuration;

        // Восстанавливаем гармоники для всего запрошенного периода
        for ($t = $start; $t <= $end; $t += $step) {
            // Нормализуем время относительно периода данных, чтобы гармоники продолжались
            $normalizedTime = ($t - $dataStart) / $periodSeconds;
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