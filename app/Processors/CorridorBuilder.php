<?php
declare(strict_types=1);

require_once __DIR__.'/../Utilities/Logger.php';
require_once __DIR__.'/../Clients/GrafanaProxyClient.php';
require_once __DIR__.'/../Formatters/ResponseFormatter.php';
require_once __DIR__.'/../Cache/CacheManagerFactory.php';
require_once __DIR__.'/DataProcessor.php';
require_once __DIR__.'/DFTProcessor.php';
require_once __DIR__.'/AnomalyDetector.php';
require_once __DIR__.'/../Utilities//PerformanceMonitor.php';
require_once __DIR__.'/StatsCacheManager.php';
require_once __DIR__.'/CorridorWidthEnsurer.php';

class CorridorBuilder
{
    private GrafanaClientInterface $client;
    private LoggerInterface $logger;
    private array $config;
    private ResponseFormatter $responseFormatter;
    private CacheManagerInterface $cacheManager;
    private DataProcessor $dataProcessor;
    private DFTProcessorInterface $dftProcessor;
    private AnomalyDetector $anomalyDetector;
    private StatsCacheManager $statsCacheManager;
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->config = $container->get('config');
        $this->logger = $container->get(LoggerInterface::class);
        $this->client = $container->get(GrafanaClientInterface::class);
        $this->cacheManager = $container->get(CacheManagerInterface::class);
        $this->dataProcessor = new DataProcessor($this->config, $this->logger);
        $this->dftProcessor = $container->get(DFTProcessorInterface::class);
        $this->responseFormatter = new ResponseFormatter($this->config);
        $this->anomalyDetector = new AnomalyDetector($this->config, $this->logger);
        $statsCalculator = new StatsCalculator(
            $this->config,
            $this->logger,
            $this->dataProcessor,
            $this->dftProcessor,
            $this->anomalyDetector
        );
        $this->statsCacheManager = new StatsCacheManager(
            $this->config,
            $this->logger,
            $this->cacheManager,
            $this->responseFormatter,
            $statsCalculator
        );

        PerformanceMonitor::init(
            $this->config['performance']['enabled'] ?? false,
            $this->config['performance']['threshold_ms'] ?? 5.0
        );
    }

    public function updateConfig(array $config): void
    {
        $this->config = $config;
        $this->dataProcessor->updateConfig($config);
        $this->responseFormatter->updateConfig($config);
        $this->anomalyDetector = new AnomalyDetector($config, $this->logger);
    }

    /**
     * @param string $query
     * @param int    $start
     * @param int    $end
     * @param int    $step
     * @param ?array $overrideShowMetrics
     * @return array  результат для Grafana
     */
    public function build(string $query, int $start, int $end, int $step, ?array $overrideShowMetrics = null): array
    {
        // берем show_metrics прямо из текущего конфига (override может туда писать)
        $showMetrics = $overrideShowMetrics ?? $this->config['dashboard']['show_metrics'];

        PerformanceMonitor::start('total_processing');
        $results = [];
        $count   = 0;

        // 1) live-данные
        PerformanceMonitor::start('prometheus_fetch');
        $raw = $this->client->queryRange($query, $start, $end, $step);
        PerformanceMonitor::end('prometheus_fetch');

        $grouped = $this->dataProcessor->groupData($raw);

        // 2) исторические данные
        $histStep = $this->config['corrdor_params']['step'];
        $offset   = $this->config['corrdor_params']['historical_offset_days'];
        $period   = $this->config['corrdor_params']['historical_period_days'];
        $histEnd  = time() - $offset * 86400;
        $histStart= $histEnd - $period * 86400;

        PerformanceMonitor::start('long_term_fetch');
        $longRaw = $this->client->queryRange($query, $histStart, $histEnd, $histStep);
        PerformanceMonitor::end('long_term_fetch');

        $longGrouped = $this->dataProcessor->groupData($longRaw);

        // 3) по каждой группе
        foreach ($grouped as $labelsJson => $orig) {
            if ($count++ >= ($this->config['timeout']['max_metrics'] ?? 10)) {
                $this->logger->warn("Превышен лимит метрик", __FILE__, __LINE__);
                break;
            }

            // пытаемся загрузить из кэша
            $cached = $this->cacheManager->loadFromCache($query, $labelsJson);
            $needRecalc = $cached === null
                || $this->cacheManager->shouldRecreateCache($query, $labelsJson, $this->config);

            if ($needRecalc) {
                $cached = $this->statsCacheManager->recalculateStats(
                    $query, $labelsJson, $orig, $longGrouped[$labelsJson] ?? [], $this->config
                );
            }

            // если мало истории — placeholder-режим
            if (count($longGrouped[$labelsJson] ?? []) < ($this->config['corrdor_params']['min_data_points'] ?? 10)) {
                $results[] = $this->statsCacheManager->processInsufficientData(
                    $query, $labelsJson, $orig, $start, $end, $step
                );
                continue;
            }

            // ресторим DFT
            $upper = $this->dftProcessor->restoreFullDFT(
                $cached['dft_upper']['coefficients'],
                $start,$end,$step,
                $cached['meta'], $cached['dft_upper']['trend']
            );
            $lower = $this->dftProcessor->restoreFullDFT(
                $cached['dft_lower']['coefficients'],
                $start,$end,$step,
                $cached['meta'], $cached['dft_lower']['trend']
            );

            // масштаб при scaleCorridor=true
            $factor = $step / $histStep;
            if (!empty($this->config['scaleCorridor']) && abs($factor-1) > 1e-6) {
                foreach ($upper as &$pt) { $pt['value'] *= $factor; }
                foreach ($lower as &$pt) { $pt['value'] *= $factor; }
                $this->logger->info("Масштабирование коридора: {$factor}", __FILE__, __LINE__);
            }

            // корректируем ширину
            list($cU,$cL) = CorridorWidthEnsurer::ensureWidth(
                $upper, $lower,
                $cached['dft_upper']['coefficients'][0]['amplitude'] ?? 0,
                $cached['dft_lower']['coefficients'][0]['amplitude'] ?? 0,
                $this->config, $this->logger
            );

            // аномалии
            $currStats = $this->anomalyDetector->calculateAnomalyStats(
                $orig, $cU, $cL,
                $this->config['corrdor_params']['default_percentiles'],
                true
            );

            // concern-метрики
            $wsize = $this->config['corrdor_params']['window_size'];
            $aboveC = $this->anomalyDetector->calculateIntegralMetric(
                $currStats['above'], $cached['meta']['anomaly_stats']['above'] ?? []
            );
            $belowC = $this->anomalyDetector->calculateIntegralMetric(
                $currStats['below'], $cached['meta']['anomaly_stats']['below'] ?? []
            );
            $aboveS = $this->anomalyDetector->calculateIntegralMetricSum(
                $currStats['above'], $cached['meta']['anomaly_stats']['above'] ?? [], $wsize
            );
            $belowS = $this->anomalyDetector->calculateIntegralMetricSum(
                $currStats['below'], $cached['meta']['anomaly_stats']['below'] ?? [], $wsize
            );

            // собираем
            $item = [
                'labels'                   => json_decode($labelsJson, true),
                'original'                 => $orig,
                'dft_upper'                => $cU,
                'dft_lower'                => $cL,
                'historical_anomaly_stats' => $cached['meta']['anomaly_stats'] ?? [],
                'current_anomaly_stats'    => $currStats,
                'anomaly_concern_above'    => $aboveC,
                'anomaly_concern_below'    => $belowC,
                'anomaly_concern_above_sum'=> $aboveS,
                'anomaly_concern_below_sum'=> $belowS,
                'dft_rebuild_count'        => $cached['meta']['dft_rebuild_count'] ?? 0,
            ];

            $results[] = $item;
        }

        PerformanceMonitor::end('total_processing');
        return $this->responseFormatter->formatForGrafana($results, $query, $showMetrics);
    }
}
