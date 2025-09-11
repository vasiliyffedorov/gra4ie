<?php
declare(strict_types=1);

namespace App\Interfaces;

interface GrafanaClientInterface
{
    public function __construct(string $grafanaUrl, string $apiToken, LoggerInterface $logger, array $blacklistDatasourceIds = [], ?CacheManagerInterface $cacheManager = null);

    public function getMetricNames(): array;

    public function getLastDataSourceType(): string;

    public function queryRange(string $metricName, int $start, int $end, int $step): array;

    public function getQueryForMetric(string $metricName): string|false;

    public function createDangerDashboard(string $metricName, string $folderUid): string|false;
}