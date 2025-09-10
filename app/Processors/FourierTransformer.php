<?php
declare(strict_types=1);

require_once __DIR__ . '/../Utilities/LoggerInterface.php';

interface FourierTransformerInterface
{
    public function calculateDFT(array $values, int $maxHarmonics, int $totalDuration, int $numPoints): array;

    public function calculateDFTValue(array $coefficients, float $normalizedTime, int $periodSeconds): float;

    public function calculateHarmonicContributions(array $coefficients, int $totalDuration, int $numPoints): array;
}

class FourierTransformer implements FourierTransformerInterface
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function calculateDFT(array $values, int $maxHarmonics, int $totalDuration, int $numPoints): array
    {
        $coefficients = [];
        $N = count($values);

        for ($k = 0; $k <= $N / 2; $k++) {
            $sumReal = 0;
            $sumImag = 0;
            for ($n = 0; $n < $N; $n++) {
                $angle = 2 * M_PI * $k * $n / $N;
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

        $contributions = $this->calculateHarmonicContributions($coefficients, $totalDuration, $numPoints);

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

    public function calculateHarmonicContributions(array $coefficients, int $totalDuration, int $numPoints): array
    {
        $contributions = [];
        $T = $totalDuration;
        $dt = $T / $numPoints;

        foreach ($coefficients as $k => $coeff) {
            $amplitude = $coeff['amplitude'];
            $phase = $coeff['phase'];
            $sum = 0;

            for ($i = 0; $i < $numPoints; $i++) {
                $t = $i * $dt;
                $angle = 2 * M_PI * $k * $t / $T + $phase;
                $value = $amplitude * cos($angle);
                $sum += abs($value) * $dt;
            }

            if ($k == 0) {
                $sum = $amplitude * $T;
            }

            $contributions[$k] = $sum;
        }

        return $contributions;
    }
}