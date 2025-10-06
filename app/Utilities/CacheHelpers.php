<?php
declare(strict_types=1);

namespace App\Utilities;

trait CacheHelpers
{
    public function buildPlaceholder(string $query, string $labelsJson, int $start, int $end, int $step): array
    {
        $labels = json_decode($labelsJson, true);
        $labels['unused_metric'] = 'true';

        $meta = [
            'query'            => $query,
            'labels'           => $labels,
            'created_at'       => time(),
            'is_placeholder'   => true,
            'dataStart'        => $start,
            'step'             => $step,
            'totalDuration'    => $end - $start,
            'config_hash'      => $this->createConfigHash($this->config),
            'dft_rebuild_count'=> 0,
            'anomaly_stats'    => [
                'above' => ['time_outside_percent'=>0,'anomaly_count'=>0,'durations'=>[],'sizes'=>[],'direction'=>'above'],
                'below' => ['time_outside_percent'=>0,'anomaly_count'=>0,'durations'=>[],'sizes'=>[],'direction'=>'below'],
                'combined'=>['time_outside_percent'=>0,'anomaly_count'=>0],
            ],
        ];

        return [
            'meta'      => $meta,
            'dft_upper' => ['coefficients'=>[], 'trend'=>['slope'=>0,'intercept'=>0]],
            'dft_lower' => ['coefficients'=>[], 'trend'=>['slope'=>0,'intercept'=>0]],
        ];
    }

    public function createConfigHash(array $config): string
    {
        return md5(serialize($config));
    }
}