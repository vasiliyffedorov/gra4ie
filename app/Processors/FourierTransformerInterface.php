<?php
declare(strict_types=1);

namespace App\Processors;

interface FourierTransformerInterface
{
    public function calculateDFT(array $values, array $times, int $maxHarmonics, int $totalDuration, int $numPoints): array;

    public function calculateDFTValue(array $coefficients, float $normalizedTime, int $periodSeconds): float;

    public function calculateHarmonicContributions(array $coefficients, array $times, int $totalDuration, int $numPoints): array;
}