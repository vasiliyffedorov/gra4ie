<?php

declare(strict_types=1);

interface DFTProcessorInterface
{
    public function generateDFT(array $bounds, int $start, int $end, int $step): array;

    public function restoreFullDFT(array $coefficients, int $start, int $end, int $step, array $meta, ?array $trend = null): array;
}