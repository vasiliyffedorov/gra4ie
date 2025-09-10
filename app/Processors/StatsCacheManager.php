<?php
declare(strict_types=1);

require_once __DIR__ . '/../Interfaces/LoggerInterface.php';
require_once __DIR__ . '/../Interfaces/CacheManagerInterface.php';
require_once __DIR__ . '/../Formatters/ResponseFormatter.php';
require_once __DIR__ . '/DataProcessor.php';
require_once __DIR__ . '/../Interfaces/DFTProcessorInterface.php';
require_once __DIR__ . '/AnomalyDetector.php';
require_once __DIR__ . '/StatsCalculator.php';

class StatsCacheManager
{
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

        $statsPayload = $this->statsCalculator->recalculateStats($query, $labelsJson, $liveData, $historyData);

        // Сохраняем в кэш
        $this->cacheManager->saveToCache($query, $labelsJson, $statsPayload, $currentConfig);

        // Обновляем счетчик rebuild
        $statsPayload['meta']['dft_rebuild_count'] = ($cached['meta']['dft_rebuild_count'] ?? 0) + 1;

        return $statsPayload;
    }

    /**
     * Обработка метрик с недостатком данных
     */
    public function processInsufficientData(
        string $query,
        string $labelsJson,
        array $liveData,
        int $start,
        int $end,
        int $step
    ): array {
        // берем placeholder
        $cached = $this->cacheManager->loadFromCache($query, $labelsJson) ?? ['meta'=>[]];

        // Можем сразу вернуть оригинал без DFT
        $bounds = $this->responseFormatter->calculateBounds($liveData, $start, $end, $step);
        $up   = $this->responseFormatter->resampleDFT($bounds['upper'], $start, $end, $step);
        $down = $this->responseFormatter->resampleDFT($bounds['lower'], $start, $end, $step);

        $labels = json_decode($labelsJson, true);
        $labels['unused_metric'] = 'true';

        return [
            'labels'                  => $labels,
            'original'                => $liveData,
            'dft_upper'               => [],
            'dft_lower'               => [],
            'historical_anomaly_stats'=> $cached['meta']['anomaly_stats'] ?? [],
            'current_anomaly_stats'   => [
                'above'=>['time_outside_percent'=>0,'anomaly_count'=>0,'durations'=>[],'sizes'=>[],'direction'=>'above'],
                'below'=>['time_outside_percent'=>0,'anomaly_count'=>0,'durations'=>[],'sizes'=>[],'direction'=>'below'],
                'combined'=>['time_outside_percent'=>0,'anomaly_count'=>0],
            ],
            'anomaly_concern_above'     => 0,
            'anomaly_concern_below'     => 0,
            'anomaly_concern_above_sum' => 0,
            'anomaly_concern_below_sum' => 0,
            'dft_rebuild_count'         => $cached['meta']['dft_rebuild_count'] ?? 0,
        ];
    }
}
