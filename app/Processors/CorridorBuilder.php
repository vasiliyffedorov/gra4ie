<?php
declare(strict_types=1);

namespace App\Processors;

use App\Utilities\Logger;
use App\Clients\GrafanaProxyClient;
use App\Formatters\ResponseFormatter;
use App\Cache\CacheManagerFactory;
use App\Utilities\PerformanceMonitor;
use App\Processors\StatsCacheManager;
use App\Processors\CorridorWidthEnsurer;
use App\DI\Container;
use App\Interfaces\GrafanaClientInterface;
use App\Interfaces\LoggerInterface;
use App\Interfaces\CacheManagerInterface;
use App\Interfaces\DataProcessorInterface;
use App\Interfaces\DFTProcessorInterface;
use App\Interfaces\AnomalyDetectorInterface;

class CorridorBuilder
{
    private GrafanaClientInterface $client;
    private Logger $logger;
    private array $config;
    private ResponseFormatter $responseFormatter;
    private CacheManagerInterface $cacheManager;
    private DataProcessorInterface $dataProcessor;
    private DFTProcessorInterface $dftProcessor;
    private AnomalyDetectorInterface $anomalyDetector;
    private StatsCacheManager $statsCacheManager;
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->config = $container->get('config');
        $this->logger = $container->get(LoggerInterface::class);
        $this->client = $container->get(GrafanaClientInterface::class);
        $this->cacheManager = $container->get(CacheManagerInterface::class);
        $this->dataProcessor = $container->get(DataProcessorInterface::class);
        $this->dftProcessor = $container->get(DFTProcessorInterface::class);
        $this->anomalyDetector = $container->get(AnomalyDetectorInterface::class);
        $this->responseFormatter = $container->get(\App\Formatters\ResponseFormatter::class);
        $this->statsCacheManager = $container->get(StatsCacheManager::class);

        \App\Utilities\PerformanceMonitor::init(
            $this->config['performance']['enabled'] ?? false,
            $this->config['performance']['threshold_ms'] ?? 5.0
        );
    }

    public function updateConfig(array $config): void
    {
        $this->config = $config;
        $this->dataProcessor->updateConfig($config);
        $this->anomalyDetector->updateConfig($config);
        $this->responseFormatter->updateConfig($config);
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
        if (empty($raw)) {
            $this->logger->warning("No data from Grafana for query $query");
            return $this->responseFormatter->formatError("No data from Grafana");
        }
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
                $this->logger->warning("Превышен лимит метрик");
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

            // автоматическое масштабирование на основе флага метрики
            $factor = $step / $histStep;
            $scaleCorridor = $cached['meta']['scaleCorridor'] ?? false;
            if ($scaleCorridor && abs($factor-1) > 1e-6) {
                foreach ($upper as &$pt) { $pt['value'] *= $factor; }
                foreach ($lower as &$pt) { $pt['value'] *= $factor; }
                $this->logger->info("Автоматическое масштабирование коридора для метрики: {$factor}");
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
                true, // raw
                false // not historical
            );
   
            // concern-метрики
            $wsize = $this->config['corrdor_params']['window_size'];
            $aboveConcerns = $this->anomalyDetector->calculateIntegralMetric(
                $currStats['above'], $cached['meta']['anomaly_stats']['above'] ?? []
            );
            $belowConcerns = $this->anomalyDetector->calculateIntegralMetric(
                $currStats['below'], $cached['meta']['anomaly_stats']['below'] ?? []
            );
            $aboveC = $aboveConcerns['total_concern'];
            $belowC = $belowConcerns['total_concern'];
   
            $aboveSums = $this->anomalyDetector->calculateIntegralMetricSum(
                $currStats['above'], $cached['meta']['anomaly_stats']['above'] ?? [], $wsize
            );
            $belowSums = $this->anomalyDetector->calculateIntegralMetricSum(
                $currStats['below'], $cached['meta']['anomaly_stats']['below'] ?? [], $wsize
            );
            $aboveS = $aboveSums['total_concern_sum'];
            $belowS = $belowSums['total_concern_sum'];
   
            // Add separate concerns
            $item['anomaly_concern_duration_above'] = $aboveConcerns['duration_concern'];
            $item['anomaly_concern_size_above'] = $aboveConcerns['size_concern'];
            $item['anomaly_concern_duration_below'] = $belowConcerns['duration_concern'];
            $item['anomaly_concern_size_below'] = $belowConcerns['size_concern'];
            $item['anomaly_concern_duration_sum_above'] = $aboveSums['duration_concern_sum'];
            $item['anomaly_concern_size_sum_above'] = $aboveSums['size_concern_sum'];
            $item['anomaly_concern_duration_sum_below'] = $belowSums['duration_concern_sum'];
            $item['anomaly_concern_size_sum_below'] = $belowSums['size_concern_sum'];

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
