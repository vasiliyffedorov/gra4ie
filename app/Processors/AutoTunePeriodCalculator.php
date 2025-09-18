<?php
declare(strict_types=1);

namespace App\Processors;

use App\Interfaces\LoggerInterface;
use App\Processors\LinearTrendCalculator;

class AutoTunePeriodCalculator
{
    private const DEFAULT_STEP_HOURS = 4;
    private const USE_HANN = false;
    private const PRINT_TOP = 5;
    private const MIN_K = 1;
    private const MAX_K_FACTOR = 0.5;

    private LoggerInterface $logger;
    private LinearTrendCalculator $trendCalculator;
    private int $stepHours;
    private int $hourSeconds = 3600;
    private int $hoursPerDay = 24;

    public function __construct(LoggerInterface $logger, array $config = [])
    {
        $this->logger = $logger;
        $this->trendCalculator = new LinearTrendCalculator($logger);
        $this->stepHours = $config['step_hours'] ?? self::DEFAULT_STEP_HOURS;
    }

    /**
     * Вычисляет оптимальный исторический период в днях на основе исторических данных метрики.
     *
     * @param array $historicalData Ассоциативный массив: timestamp (int) => value (float), отсортированный по времени
     * @return float Оптимальное значение для corrdor_params.historical_period_days
     * @throws \InvalidArgumentException Если данных недостаточно
     */
    public function calculateOptimalPeriod(array $historicalData): float
    {
        if (empty($historicalData)) {
            throw new \InvalidArgumentException('Historical data is empty');
        }

        // Предобработка: обрезать ведущие нули
        $data = $this->preprocessData($historicalData);

        if (empty($data)) {
            throw new \InvalidArgumentException('No valid data after preprocessing');
        }

        // Интерполяция на сетку
        $filled = $this->interpolateToGrid($data, $this->stepHours);
        $valuesFull = array_values($filled);
        $nTotal = count($valuesFull);

        if ($nTotal < 8) {
            $this->logger->warning('Insufficient data points after interpolation: ' . $nTotal);
            return $nTotal * $this->stepHours / $this->hoursPerDay;
        }

        // Перебор cut
        $best = $this->findBestCut($valuesFull, $nTotal);

        if ($best['score'] === -INF) {
            $this->logger->warning('No valid cut found');
            return $nTotal * $this->stepHours / $this->hoursPerDay;
        }

        $periodHours = $best['T'];
        $totalHours = $nTotal * $this->stepHours;
        $maxMultiples = (int) floor($totalHours / $periodHours);
        $maxTotalHours = $maxMultiples * $periodHours;

        return $maxTotalHours / $this->hoursPerDay;
    }

    private function preprocessData(array $raw): array
    {
        $allZeros = true;
        $firstNonzeroKey = null;
        foreach ($raw as $t => $v) {
            if ($v > 0) {
                $firstNonzeroKey = $t;
                $allZeros = false;
                break;
            }
        }

        if (!$allZeros && $firstNonzeroKey !== null) {
            $keys = array_keys($raw);
            $trimIndex = array_search($firstNonzeroKey, $keys);
            return array_slice($raw, $trimIndex, null, true);
        }

        return $raw;
    }

    private function interpolateToGrid(array $data, int $stepHours): array
    {
        $timestamps = array_keys($data);
        $start = reset($timestamps);
        $end = end($timestamps);

        $stepSeconds = $stepHours * $this->hourSeconds;

        // Выравнивание start и end
        $startMod = $start % $stepSeconds;
        $startAligned = $start + ($stepSeconds - $startMod) % $stepSeconds;
        $endMod = $end % $stepSeconds;
        $endAligned = $end - $endMod;

        $filled = [];
        for ($t = $startAligned; $t <= $endAligned; $t += $stepSeconds) {
            if (isset($data[$t])) {
                $filled[$t] = $data[$t];
            } else {
                // Линейная интерполяция
                $t0 = null;
                $t1 = null;
                $v0 = null;
                $v1 = null;
                foreach ($timestamps as $ts) {
                    if ($ts <= $t && ($t0 === null || $ts > $t0)) {
                        $t0 = $ts;
                        $v0 = $data[$ts];
                    }
                    if ($ts >= $t && ($t1 === null || $ts < $t1)) {
                        $t1 = $ts;
                        $v1 = $data[$ts];
                    }
                }
                if ($t0 !== null && $t1 !== null && $t1 > $t0 && $v0 !== null && $v1 !== null) {
                    $filled[$t] = $v0 + ($v1 - $v0) * (($t - $t0) / ($t1 - $t0));
                } elseif ($t0 !== null && $v0 !== null) {
                    $filled[$t] = $v0;
                } elseif ($t1 !== null && $v1 !== null) {
                    $filled[$t] = $v1;
                } else {
                    $filled[$t] = NAN;
                }
            }
        }

        // Удалить NaN если есть
        $filled = array_filter($filled, function($v) { return !is_nan($v); });

        ksort($filled);
        return $filled;
    }

    private function findBestCut(array $valuesFull, int $nTotal): array
    {
        $best = [
            'cut' => 0,
            'score' => -INF,
            'k' => null,
            'T' => null,
            'pow' => null,
            'n' => null,
            'top' => [],
        ];

        for ($cut = 0; $cut <= $nTotal - 2; ++$cut) {
            $slice = array_slice($valuesFull, 0, $nTotal - $cut);
            $n = count($slice);
            if ($n < 8) {
                break;
            }

            // Проверка NaN
            if (array_filter($slice, 'is_nan')) {
                continue;
            }

            // Детренд
            $detrendResult = $this->linearDetrend($slice);
            $x = $detrendResult['detrended'];
            $a = $detrendResult['intercept'];
            $b = $detrendResult['slope'];

            if (self::USE_HANN) {
                $x = $this->applyHann($x);
            }

            $maxK = max(self::MIN_K, (int) floor($n * self::MAX_K_FACTOR) - 1);
            $power = $this->dftPower($x, self::MIN_K, $maxK);

            if (empty($power)) {
                continue;
            }

            // Дисперсия
            $meanVal = array_sum($slice) / $n;
            $varTotal = 0;
            foreach ($slice as $val) {
                $varTotal += pow($val - $meanVal, 2);
            }
            $varTotal /= $n;
            if ($varTotal < 1e-10) {
                continue;
            }

            arsort($power);
            $bestK = array_key_first($power);
            $bestP = $power[$bestK];
            $T = ($n / $bestK) * $this->stepHours;

            // Top peaks
            $topPeaks = array_slice($power, 0, self::PRINT_TOP, true);
            $topKs = array_keys($topPeaks);

            $reims = $this->getTopReIm($x, $topKs, $n);
            $reconDetrended = $this->reconstructFromReIm($reims, $topKs, $n);

            if (self::USE_HANN) {
                $reconDetrended = $this->undoHann($reconDetrended, $n);
            }

            $recon = [];
            for ($i = 0; $i < $n; ++$i) {
                $recon[] = $a + $b * $i + $reconDetrended[$i];
            }

            $mse = $this->computeMSE($slice, $recon);
            $r2 = $varTotal > 0 ? 1.0 - ($mse / $varTotal) : 0.0;

            $med = $this->median($power);
            $domMed = $bestP / max($med, 1e-12);
            $score = $domMed * $r2;

            if ($score > $best['score'] || ($score == $best['score'] && $bestP > ($best['pow'] ?? 0))) {
                $best = [
                    'cut' => $cut,
                    'score' => $score,
                    'k' => $bestK,
                    'T' => $T,
                    'pow' => $bestP,
                    'n' => $n,
                    'top' => $topPeaks,
                ];
            }
        }

        return $best;
    }

    private function linearDetrend(array $values): array
    {
        $n = count($values);
        $times = range(0, $n - 1); // Индексы как в скрипте

        $trend = $this->trendCalculator->calculateTrend($values, $times);
        $a = $trend['intercept'];
        $b = $trend['slope'];

        $detrended = [];
        foreach ($values as $i => $value) {
            $detrended[] = $value - ($a + $b * $times[$i]);
        }

        return ['detrended' => $detrended, 'intercept' => $a, 'slope' => $b];
    }

    private function applyHann(array $x): array
    {
        $n = count($x);
        if ($n <= 1) {
            return $x;
        }
        foreach ($x as $i => $val) {
            $w = 0.5 * (1.0 - cos(2.0 * M_PI * $i / ($n - 1)));
            $x[$i] *= $w;
        }
        return $x;
    }

    private function undoHann(array $recon, int $n): array
    {
        foreach ($recon as $i => $val) {
            $w = 0.5 * (1.0 - cos(2.0 * M_PI * $i / ($n - 1)));
            if ($w > 1e-12) {
                $recon[$i] /= $w;
            }
        }
        return $recon;
    }

    private function dftPower(array $x, int $minK, int $maxK): array
    {
        $n = count($x);
        $power = [];
        for ($k = $minK; $k <= $maxK; ++$k) {
            $re = 0.0;
            $im = 0.0;
            for ($t = 0; $t < $n; ++$t) {
                $ang = 2 * M_PI * $t * $k / $n;
                $re += $x[$t] * cos($ang);
                $im -= $x[$t] * sin($ang);
            }
            $power[$k] = $re * $re + $im * $im;
        }
        return $power;
    }

    private function getTopReIm(array $x, array $topKs, int $n): array
    {
        $reims = [];
        foreach ($topKs as $k) {
            $re = 0.0;
            $im = 0.0;
            for ($t = 0; $t < $n; ++$t) {
                $ang = 2 * M_PI * $t * $k / $n;
                $re += $x[$t] * cos($ang);
                $im -= $x[$t] * sin($ang);
            }
            $reims[$k] = [$re, $im];
        }
        return $reims;
    }

    private function reconstructFromReIm(array $reims, array $topKs, int $n): array
    {
        $recon = array_fill(0, $n, 0.0);
        for ($t = 0; $t < $n; ++$t) {
            $realPart = 0.0;
            foreach ($topKs as $k) {
                [$re, $im] = $reims[$k];
                $ang = 2 * M_PI * $t * $k / $n;
                $realPart += $re * cos($ang) - $im * sin($ang);
            }
            $recon[$t] = $realPart / $n;
        }
        return $recon;
    }

    private function computeMSE(array $orig, array $recon): float
    {
        $n = count($orig);
        if ($n !== count($recon)) {
            return NAN;
        }
        $sum = 0.0;
        for ($i = 0; $i < $n; ++$i) {
            $sum += ($orig[$i] - $recon[$i]) ** 2;
        }
        return $sum / $n;
    }

    private function median(array $a): float
    {
        $b = array_values($a);
        sort($b);
        $n = count($b);
        if ($n === 0) {
            return 0.0;
        }
        $m = (int) ($n / 2);
        return $n % 2 ? $b[$m] : 0.5 * ($b[$m - 1] + $b[$m]);
    }
}