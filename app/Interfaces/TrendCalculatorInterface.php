<?php
declare(strict_types=1);

namespace App\Interfaces;

interface TrendCalculatorInterface
{
    public function calculateTrend(array $values, array $times): array;
}