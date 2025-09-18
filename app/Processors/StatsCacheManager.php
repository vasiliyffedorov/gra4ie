<?php
declare(strict_types=1);

namespace App\Processors;

use App\Interfaces\LoggerInterface;
use App\Interfaces\CacheManagerInterface;
use App\Interfaces\GrafanaClientInterface;
use App\Formatters\ResponseFormatter;
use App\Interfaces\DataProcessorInterface;
use App\Interfaces\DFTProcessorInterface;
use App\Interfaces\AnomalyDetectorInterface;

class StatsCacheManager {
    private array $config;
    private LoggerInterface $logger;
    private CacheManagerInterface $cacheManager;
    private ResponseFormatter $responseFormatter;
    private DataProcessorInterface $dataProcessor;
    private DFTProcessorInterface $dftProcessor;
    private AnomalyDetectorInterface $anomalyDetector;
    private GrafanaClientInterface $client;

    public function __construct(
        array $config,
        LoggerInterface $logger,
        CacheManagerInterface $cacheManager,
        ResponseFormatter $responseFormatter,
        DataProcessorInterface $dataProcessor,
        DFTProcessorInterface $dftProcessor,
        AnomalyDetectorInterface $anomalyDetector,
        GrafanaClientInterface $client
    ) {
        if (!isset($config['corrdor_params']['step']) || !isset($config['cache'])) {
            throw new \InvalidArgumentException('Config must contain corrdor_params.step and cache');
        }
        $this->config = $config;
        $this->logger = $logger;
        $this->cacheManager = $cacheManager;
        $this->responseFormatter = $responseFormatter;
        $this->dataProcessor = $dataProcessor;
        $this->dftProcessor = $dftProcessor;
        $this->anomalyDetector = $anomalyDetector;
        $this->client = $client;
    }

    /**
     * Пересчитывает DFT и статистики аномалий, сохраняя результат в кэш.
     *
     * @param string $query Запрос для Grafana
     * @param string $labelsJson JSON-строка с лейблами метрики
     * @param array $liveData Живые данные (не используются в расчете, но для совместимости)
     * @param array $historyData Исторические данные: [['time' => int, 'value' => float], ...]
     * @param array $currentConfig Текущая конфигурация: ['corrdor_params' => [...], ...]
     * @return array Payload с meta, dft_upper и dft_lower
     * @throws \InvalidArgumentException Если historyData недостаточно или config некорректен
     */
    public function recalculateStats(
        string $query,
        string $labelsJson,
        array $liveData,
        array $historyData,
        array $currentConfig
    ): array {
        if (empty($query) || empty($labelsJson)) {
            throw new \InvalidArgumentException('Query and labelsJson must not be empty');
        }
        if (!isset($currentConfig['corrdor_params']['step']) || !isset($currentConfig['corrdor_params']['min_data_points'])) {
            throw new \InvalidArgumentException('Config must contain corrdor_params.step and min_data_points');
        }

        // Если метрика помечена как unused — ничего не делаем
        $cached = $this->cacheManager->loadFromCache($query, $labelsJson);
        if (isset($cached['meta']['labels']['unused_metric'])) {
            $this->logger->info("Пропуск пересчета unused_metric");
            return $cached;
        }

        // Обновляем конфиг в процессорах
        $this->dataProcessor->updateConfig($currentConfig);
        $this->anomalyDetector->updateConfig($currentConfig);
        
        // Рассчитываем DFT и статистики
        $range = $this->dataProcessor->getActualDataRange($historyData);
        $longStart = $range['start'];
        $longEnd = $range['end'];
        $longStep = $currentConfig['corrdor_params']['step'];

        // Автоматическое определение scaleCorridor
        $periodDays = $currentConfig['corrdor_params']['historical_period_days'];
        $halfPeriodSec = (int)(($periodDays / 2) * 86400);
        $halfStart = $longEnd - $halfPeriodSec;
        $halfStep = (int)($longStep / 2);
        if ($halfStep < 1) {
            $halfStep = $longStep;
        }
        $halfRaw = $this->client->queryRange($query, $halfStart, $longEnd, $halfStep);
        $halfGrouped = $this->dataProcessor->groupData($halfRaw);
        $halfData = $halfGrouped[$labelsJson] ?? [];
        $minPts = $currentConfig['corrdor_params']['min_data_points'];
        $minHalfPts = (int)($minPts / 2);
        $scaleCorridor = false;
        if (count($halfData) >= $minHalfPts) {
            $mainValues = array_filter(array_column($historyData, 'value'), fn($v) => $v !== null);
            $mainAvg = count($mainValues) > 0 ? array_sum($mainValues) / count($mainValues) : 0;
            $halfValues = array_filter(array_column($halfData, 'value'), fn($v) => $v !== null);
            $halfAvg = count($halfValues) > 0 ? array_sum($halfValues) / count($halfValues) : 0;
            $ratio = $halfAvg > 0 ? $mainAvg / $halfAvg : 0;
            $tolerance = 0.2;
            $scaleCorridor = ($halfAvg > 0 && abs($ratio - 2) <= $tolerance);
            $this->logger->info("Scale corridor for {$labelsJson}: " . ($scaleCorridor ? 'true' : 'false') . ", ratio: {$ratio}");
        } else {
            $this->logger->info("Insufficient half-period data for scale detection on {$labelsJson}");
        }

        $minPts = $currentConfig['corrdor_params']['min_data_points'];
        if (count($historyData) < $minPts) {
            $this->logger->warning("Недостаточно долгосрочных данных, placeholder");
            return $this->buildPlaceholder($query, $labelsJson, $longStart, $longEnd, $longStep);
        }

        // Генерируем DFT
        $bounds = $this->dataProcessor->calculateBounds($historyData, $longStart, $longEnd, $longStep);
        $dftResult = $this->dftProcessor->generateDFT($bounds, $longStart, $longEnd, $longStep);

        // Рассчитываем stddev для original historyData
        $values = array_column($historyData, 'value');
        $stddev = 0.0;
        if (!empty($values)) {
            $mean = array_sum($values) / count($values);
            $variance = array_sum(array_map(fn($v) => pow($v - $mean, 2), $values)) / count($values);
            $stddev = sqrt($variance);
            $this->logger->debug("Stddev для historyData: $stddev");
        }

        // Фильтруем «нулевые» гармоники
        $dftResult['upper']['coefficients'] = array_filter(
            $dftResult['upper']['coefficients'],
            fn($c) => $c['amplitude'] >= 1e-12
        );
        $dftResult['lower']['coefficients'] = array_filter(
            $dftResult['lower']['coefficients'],
            fn($c) => $c['amplitude'] >= 1e-12
        );

        // Восстанавливаем траектории
        $meta = [
            'dataStart'         => $longStart,
            'step'              => $longStep,
            'totalDuration'     => $longEnd - $longStart,
            'config_hash'       => $this->createConfigHash($currentConfig),
            'dft_rebuild_count' => ($cached['meta']['dft_rebuild_count'] ?? 0) + 1,
            'labels'            => json_decode($labelsJson, true),
            'created_at'        => time(),
            'scaleCorridor'     => $scaleCorridor,
            'orig_stddev'       => $stddev,
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

        // Статистики аномалий
        $stats = $this->anomalyDetector->calculateAnomalyStats(
            $historyData, $upperSeries, $lowerSeries,
            $currentConfig['corrdor_params']['default_percentiles'],
            false, // raw
            true, // isHistorical
            $longStep // actual step for hist
        );
        $meta['anomaly_stats'] = $stats;

        // Payload
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

        // Сохраняем в кэш
        $this->cacheManager->saveToCache($query, $labelsJson, $payload, $currentConfig);

        return $payload;
    }

    private function buildPlaceholder(string $query, string $labelsJson, int $start, int $end, int $step): array
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

    private function createConfigHash(array $config): string
    {
        return md5(serialize($config));
    }
}
?>
