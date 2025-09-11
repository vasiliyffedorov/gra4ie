<?php
declare(strict_types=1);

namespace App\Processors;

use App\Interfaces\LoggerInterface;
use App\Interfaces\CacheManagerInterface;
use App\Formatters\ResponseFormatter;
use App\Processors\DataProcessor;
use App\Interfaces\DFTProcessorInterface;
use App\Processors\AnomalyDetector;
use App\Processors\StatsCalculator;

class StatsCacheManager {
    private array $config;
    private LoggerInterface $logger;
    private CacheManagerInterface $cacheManager;
    private ResponseFormatter $responseFormatter;
    private StatsCalculator $statsCalculator;

    public function __construct(
        array $config,
        LoggerInterface $logger,
        CacheManagerInterface $cacheManager,
        ResponseFormatter $responseFormatter,
        StatsCalculator $statsCalculator
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->cacheManager = $cacheManager;
        $this->responseFormatter = $responseFormatter;
        $this->statsCalculator = $statsCalculator;
    }

    /**
     * Пересчет DFT + статистики аномалий с сохранением в кэш
     */
    public function recalculateStats(
        string $query,
        string $labelsJson,
        array $liveData,
        array $historyData,
        array $currentConfig
    ): array {
        // Если метрика помечена как unused — ничего не делаем
        $cached = $this->cacheManager->loadFromCache($query, $labelsJson);
        if (isset($cached['meta']['labels']['unused_metric'])) {
            $this->logger->info("Пропуск пересчета unused_metric", __FILE__, __LINE__);
            return $cached;
        }

        // Обновляем конфиг в калькуляторе
        $this->statsCalculator->updateConfig($currentConfig);

        // Рассчитываем DFT и статистики
        $range = $this->statsCalculator->getActualDataRange($historyData);
        $longStart = $range['start'];
        $longEnd = $range['end'];
        $longStep = $currentConfig['corrdor_params']['step'];

        $minPts = $currentConfig['corrdor_params']['min_data_points'];
        if (count($historyData) < $minPts) {
            $this->logger->warn("Недостаточно долгосрочных данных, placeholder", __FILE__, __LINE__);
            return $this->buildPlaceholder($query, $labelsJson, $longStart, $longEnd, $longStep);
        }

        // Генерируем DFT
        $bounds = $this->statsCalculator->calculateBounds($historyData, $longStart, $longEnd, $longStep);
        $dftResult = $this->statsCalculator->generateDFT($bounds, $longStart, $longEnd, $longStep);

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
        ];

        $upperSeries = $this->statsCalculator->restoreFullDFT(
            $dftResult['upper']['coefficients'],
            $longStart, $longEnd, $longStep,
            $meta, $dftResult['upper']['trend']
        );
        $lowerSeries = $this->statsCalculator->restoreFullDFT(
            $dftResult['lower']['coefficients'],
            $longStart, $longEnd, $longStep,
            $meta, $dftResult['lower']['trend']
        );

        // Статистики аномалий
        $stats = $this->statsCalculator->calculateAnomalyStats(
            $historyData, $upperSeries, $lowerSeries,
            $currentConfig['corrdor_params']['default_percentiles']
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
