<?php
declare(strict_types=1);

namespace App\Interfaces;

interface CacheManagerInterface
{
    public function __construct(string $dbPath, LoggerInterface $logger, int $maxTtl = 86400, array $config = []);

    public function loadFromCache(string $query, string $labelsJson): ?array;

    public function saveToCache(string $query, string $labelsJson, array $payload, array $config): void;

    public function shouldRecreateCache(string $query, string $labelsJson, array $config): bool;

    public function createConfigHash(array $config): string;

    // L1 autoscale cache (no TTL cleanup). Keyed by (query, metric_hash)
    public function saveAutoscaleL1(string $query, string $labelsJson, array $info): bool;

    public function loadAutoscaleL1(string $query, string $labelsJson): ?array;

    // Permanent metrics cache (request_md5 + optimal_period + scale flags)
    public function saveMetricsCacheL1(string $query, string $labelsJson, array $info): bool;

    public function loadMetricsCacheL1(string $query, string $labelsJson): ?array;

    // Grafana individual metrics cache
    public function saveGrafanaIndividualMetric(int $instanceId, string $metricKey, array $metricData): bool;

    public function loadGrafanaIndividualMetrics(int $instanceId): array;

    public function updateGrafanaIndividualMetrics(int $instanceId, array $metrics): bool;

    // Grafana instances
    public function getGrafanaInstanceIdByUrl(string $url): ?int;
}