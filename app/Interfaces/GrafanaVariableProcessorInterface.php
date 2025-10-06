<?php
declare(strict_types=1);

namespace App\Interfaces;

interface GrafanaVariableProcessorInterface
{
    public function processVariables(string $url, string $token, string $dashboardUid): array;
}