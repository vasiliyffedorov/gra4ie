<?php
declare(strict_types=1);

interface GrafanaClientInterface
{
    public function __construct(string $grafanaUrl, string $apiToken, LoggerInterface $logger, array $blacklistDatasourceIds = []);

    public function getMetricNames(): array;

    public function getLastDataSourceType(): string;

    public function queryRange(string $metricName, int $start, int $end, int $step): array;
}