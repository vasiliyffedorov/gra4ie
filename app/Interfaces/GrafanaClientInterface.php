<?php
declare(strict_types=1);

namespace App\Interfaces;

interface GrafanaClientInterface
{
    public function __construct(array $instance, LoggerInterface $logger, ?CacheManagerInterface $cacheManager = null);

    public function getMetricNames(): array;

    public function getLastDataSourceType(): string;

    public function queryRange(string $metricName, int $start, int $end, int $step): array;

    public function getQueryForMetric(string $metricName): string|false;

    public function getNormalizedRequestMd5(string $metricName): string|false;

    public function createDangerDashboard(string $metricName, string $folderUid): string|false;
}