<?php
declare(strict_types=1);

namespace App\Processors;

use App\Interfaces\LoggerInterface;
use App\Interfaces\DataProcessorInterface;
use App\Interfaces\DFTProcessorInterface;
use App\Interfaces\AnomalyDetectorInterface;
use App\Processors\CorridorWidthEnsurer;

class StatsCalculator
{
    private array $config;
    private LoggerInterface $logger;
    private DataProcessorInterface $dataProcessor;
    private DFTProcessorInterface $dftProcessor;
    private AnomalyDetectorInterface $anomalyDetector;

    public function __construct(array $config, LoggerInterface $logger, DataProcessorInterface $dataProcessor, DFTProcessorInterface $dftProcessor, AnomalyDetectorInterface $anomalyDetector)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->dataProcessor = $dataProcessor;
        $this->dftProcessor = $dftProcessor;
        $this->anomalyDetector = $anomalyDetector;
    }

    public function updateConfig(array $config): void
    {
        $this->config = $config;
        $this->dataProcessor->updateConfig($config);
        $this->anomalyDetector->updateConfig($config);
    }

    public function getActualDataRange(array $data, ?int $defaultStart = null, ?int $defaultEnd = null): array
    {
        return $this->dataProcessor->getActualDataRange($data, $defaultStart, $defaultEnd);
    }

    public function calculateBounds(array $data, int $start, int $end, int $step): array
    {
        return $this->dataProcessor->calculateBounds($data, $start, $end, $step);
    }

    public function generateDFT(array $bounds, int $start, int $end, int $step): array
    {
        return $this->dftProcessor->generateDFT($bounds, $start, $end, $step);
    }

    public function restoreFullDFT(array $coefficients, int $start, int $end, int $step, array $meta, ?array $trend = null): array
    {
        return $this->dftProcessor->restoreFullDFT($coefficients, $start, $end, $step, $meta, $trend);
    }

    public function calculateAnomalyStats(
        array $dataPoints,
        array $upperBound,
        array $lowerBound,
        ?array $percentileConfig = null,
        bool $raw = false
    ): array {
        return $this->anomalyDetector->calculateAnomalyStats($dataPoints, $upperBound, $lowerBound, $percentileConfig, $raw);
    }

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


    public function recalculateStats(string $query, string $labelsJson, array $liveData, array $historyData): array
    {
        $range = $this->dataProcessor->getActualDataRange($historyData);
        $longStart = $range['start'];
        $longEnd = $range['end'];
        $longStep = $this->config['corrdor_params']['step'];

        $minPts = $this->config['corrdor_params']['min_data_points'];
        if (count($historyData) < $minPts) {
            $this->logger->warning("Недостаточно долгосрочных данных, placeholder в " . basename(__FILE__) . ":" . __LINE__);
            return $this->buildPlaceholder($query, $labelsJson, $longStart, $longEnd, $longStep);
        }

        // 1) генерируем DFT
        $bounds = $this->dataProcessor->calculateBounds($historyData, $longStart, $longEnd, $longStep);
        $dftResult = $this->dftProcessor->generateDFT($bounds, $longStart, $longEnd, $longStep);

        // фильтруем «нулевые» гармоники
        $dftResult['upper']['coefficients'] = array_filter(
            $dftResult['upper']['coefficients'],
            fn($c) => $c['amplitude'] >= 1e-12
        );
        $dftResult['lower']['coefficients'] = array_filter(
            $dftResult['lower']['coefficients'],
            fn($c) => $c['amplitude'] >= 1e-12
        );

        // 2) восстанавливаем траектории
        $meta = [
            'dataStart'         => $longStart,
            'step'              => $longStep,
            'totalDuration'     => $longEnd - $longStart,
            'config_hash'       => $this->createConfigHash($this->config),
            'dft_rebuild_count' => 1,
            'labels'            => json_decode($labelsJson, true),
            'created_at'        => time(),
        ];

        $upperSeries = $this->dftProcessor->restoreFullDFT(
            $dftResult['upper']['coefficients'],
            $longStart, $longEnd, $longStep,
            $meta, $dftResult['upper']['trend']
        );
        $lowerSeries = $this->dftProcessor->restoreFullDFT(
            $dftResult['lower']['coefficients'],
            $longStart, $longEnd, $longStep,
            $meta, $dftResult['lower']['trend']
        );

        // 3) статистики аномалий
        $stats = $this->anomalyDetector->calculateAnomalyStats(
            $historyData, $upperSeries, $lowerSeries,
            $this->config['corrdor_params']['default_percentiles']
        );
        $meta['anomaly_stats'] = $stats;

        // 4) payload
        $payload = [
            'meta'      => $meta,
            'dft_upper' => [
                'coefficients' => $dftResult['upper']['coefficients'],
                'trend'        => $dftResult['upper']['trend']
            ],
            'dft_lower' => [
                'coefficients' => $dftResult['lower']['coefficients'],
                'trend'        => $dftResult['lower']['trend']
            ],
        ];

        return $payload;
    }

}