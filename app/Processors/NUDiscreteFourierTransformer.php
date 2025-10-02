<?php
declare(strict_types=1);

namespace App\Processors;

use App\Interfaces\LoggerInterface;
use App\Processors\FourierTransformerInterface;

class NUDiscreteFourierTransformer implements FourierTransformerInterface
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function calculateDFT(array $values, array $times, int $maxHarmonics, int $totalDuration, int $numPoints): array
    {
        $coefficients = [];
        $N = count($values);

        // Нормализуем времена относительно начала
        $t0 = $times[0] ?? 0;
        $normalizedTimes = array_map(fn($t) => $t - $t0, $times);

        for ($k = 0; $k <= $N / 2; $k++) {
            $sumReal = 0;
            $sumImag = 0;
            for ($n = 0; $n < $N; $n++) {
                $angle = 2 * M_PI * $k * $normalizedTimes[$n] / $totalDuration;
                $sumReal += $values[$n] * cos($angle);
                $sumImag -= $values[$n] * sin($angle);
            }
            $amplitude = sqrt($sumReal * $sumReal + $sumImag * $sumImag) / ($k == 0 ? $N : $N / 2);
            $phase = ($sumReal == 0 && $sumImag == 0) ? 0 : atan2($sumImag, $sumReal);

            $coefficients[$k] = [
                'amplitude' => $amplitude,
                'phase' => $phase
            ];
        }

        $contributions = $this->calculateHarmonicContributions($coefficients, $times, $totalDuration, $numPoints);

        $minContribution = $totalDuration * (2 / M_PI) * 1e-6; // Default min
        $filteredCoefficients = [];
        foreach ($coefficients as $k => $coeff) {
            if ($contributions[$k] >= $minContribution) {
                $filteredCoefficients[$k] = $coeff;
            }
        }

        if (!isset($filteredCoefficients[0]) && isset($coefficients[0])) {
            $filteredCoefficients[0] = $coefficients[0];
        }

        $sortedCoefficients = $filteredCoefficients;
        uasort($sortedCoefficients, function ($a, $b) use ($contributions, $filteredCoefficients) {
            $kA = array_search($a, $filteredCoefficients, true);
            $kB = array_search($b, $filteredCoefficients, true);
            return $contributions[$kB] <=> $contributions[$kA];
        });

        $selectedCoefficients = [];
        $selectedCoefficients[0] = $filteredCoefficients[0] ?? ['amplitude' => 0, 'phase' => 0];
        $count = 1;
        foreach ($sortedCoefficients as $k => $coeff) {
            if ($k != 0 && $count < $maxHarmonics) {
                $selectedCoefficients[$k] = $coeff;
                $count++;
            }
        }

        return $selectedCoefficients;
    }

    public function calculateDFTValue(array $coefficients, float $normalizedTime, int $periodSeconds): float
    {
        $value = 0;

        foreach ($coefficients as $harmonic => $coeff) {
            if ($harmonic == 0) {
                $value += $coeff['amplitude'];
                continue;
            }
            $frequency = $harmonic;
            $angle = 2 * M_PI * $frequency * $normalizedTime + $coeff['phase'];
            $contribution = $coeff['amplitude'] * cos($angle);
            $value += $contribution;
        }

        return $value;
    }

    public function calculateHarmonicContributions(array $coefficients, array $times, int $totalDuration, int $numPoints): array
    {
        $contributions = [];
        $T = $totalDuration;

        // Вычисляем dt между точками
        $dts = [];
        for ($i = 0; $i < $numPoints - 1; $i++) {
            $dts[] = $times[$i + 1] - $times[$i];
        }
        // Для последней точки, используем средний dt или предыдущий
        $dts[] = $dts ? $dts[count($dts) - 1] : $T / $numPoints;

        foreach ($coefficients as $k => $coeff) {
            $amplitude = $coeff['amplitude'];
            $phase = $coeff['phase'];
            $sum = 0;

            for ($i = 0; $i < $numPoints; $i++) {
                $t = $times[$i] - $times[0]; // Нормализуем относительно начала
                $angle = 2 * M_PI * $k * $t / $T + $phase;
                $value = $amplitude * cos($angle);
                $sum += abs($value) * $dts[$i];
            }

            if ($k == 0) {
                $sum = $amplitude * ($times[$numPoints - 1] - $times[0]);
            }

            $contributions[$k] = $sum;
        }

        return $contributions;
    }
}