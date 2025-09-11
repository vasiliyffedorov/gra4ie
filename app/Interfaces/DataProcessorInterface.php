<?php
declare(strict_types=1);

namespace App\Interfaces;

interface DataProcessorInterface
{
    public function updateConfig(array $config): void;

    public function getActualDataRange(array $data, ?int $defaultStart = null, ?int $defaultEnd = null): array;

    public function calculateBounds(array $data, int $start, int $end, int $step): array;

    public function groupData(array $rawData): array;
}