<?php
declare(strict_types=1);

namespace App\Processors;

use App\Utilities\Logger;
use App\Clients\GrafanaProxyClient;
use App\Formatters\ResponseFormatter;
use App\Cache\CacheManagerFactory;
use App\Utilities\PerformanceMonitor;
use App\Processors\StatsCacheManager;
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
        PerformanceMonitor::end('prometheus_fetch');

        $grouped = $this->dataProcessor->groupData($raw);
        if (empty($raw)) {
            $this->logger->warning("No live data from Grafana for query $query");
        }

        // Historical data fetch is now handled inside recalculateStats with adaptive period
        $longGrouped = []; // Empty, to trigger fetch in recalculateStats

        // 3) по каждой группе
        foreach ($grouped as $labelsJson => $orig) {
            if ($count++ >= ($this->config['timeout']['max_metrics'] ?? 10)) {
                $this->logger->warning("Превышен лимит метрик");
                break;
            }

            // L1 integration: check for STALE md5 mismatch
            $requestMd5 = $this->client->getNormalizedRequestMd5($query) ?: '';
            $l1 = $this->cacheManager->loadMetricsCacheL1($query, $labelsJson);
            $l1Status = 'MISS';
            if ($l1) {
                if ($l1['request_md5'] === $requestMd5) {
                    $l1Status = 'HIT';
                } else {
                    $l1Status = 'STALE';
                    $needRecalc = true;
                    $this->logger->info('L1 STALE md5 mismatch for ' . $query . ': old=' . $l1['request_md5'] . ', new=' . $requestMd5 . ', forcing recalc');
                }
            }

            // пытаемся загрузить из кэша
            $cached = $this->cacheManager->loadFromCache($query, $labelsJson);
            $needRecalc = $cached === null
                || $this->cacheManager->shouldRecreateCache($query, $labelsJson, $this->config);

            if ($needRecalc) {
                $cached = $this->statsCacheManager->recalculateStats(
                    $query, $labelsJson, $orig, [], $this->config
                );
            }

            $stddev = $cached['meta']['orig_stddev'] ?? 0.0;
            $this->logger->debug("Извлечен stddev из кэша для {$labelsJson}: $stddev");


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

            // Применяем грязный хак к коридору
            $this->applyCorridorHack($upper, $lower);

            // Применяем сдвиги для коррекции ширины коридора
            $upperShift = $cached['upper_shift'] ?? 0.0;
            $lowerShift = $cached['lower_shift'] ?? 0.0;
            if ($upperShift > 0.0) {
                foreach ($upper as &$point) {
                    $point['value'] += $upperShift;
                }
                unset($point);
            }
            if ($lowerShift > 0.0) {
                foreach ($lower as &$point) {
                    $point['value'] -= $lowerShift;
                }
                unset($point);
            }

            // применяем автоскейл (масштабирование коридора) если он включён детектором
            // масштабируем значения коридора пропорционально отношению шага борды к историческому шагу из кэша
            $histStep = (int)($cached['meta']['step'] ?? $step);
            $this->logger->debug("CorridorBuilder: current step = $step, histStep = $histStep");
            $needScale = (bool)($cached['meta']['scaleCorridor'] ?? false);
            if ($l1Status === 'HIT' && isset($l1['scale_corridor'])) {
                $needScale = (bool)$l1['scale_corridor'];
            }
            $this->logger->info("Scale check for {$labelsJson}: l1Status={$l1Status}, scaleCorridor=" . ($cached['meta']['scaleCorridor'] ?? 'null') . ", l1_scale=" . ($l1['scale_corridor'] ?? 'null') . ", needScale={$needScale}, histStep={$histStep}, step={$step}");
            if ($needScale && $histStep > 0 && $step > 0 && $histStep !== $step) {
                $factor = $step / $histStep;
                foreach ($upper as &$p) {
                    if (isset($p['value'])) {
                        $p['value'] = (float)$p['value'] * $factor;
                    }
                }
                unset($p);
                foreach ($lower as &$p) {
                    if (isset($p['value'])) {
                        $p['value'] = (float)$p['value'] * $factor;
                    }
                }
                unset($p);
                $this->logger->info(
                    "Applied autoscale to corridor: factor={$factor} (board_step={$step}, hist_step={$histStep}) for labels={$labelsJson}"
                );
            }

            // коридор строится без коррекции ширины
            $cU = $upper;
            $cL = $lower;

            // Debug: log corridor values for cgate__cgatecron
            if (str_contains($query, 'cgate__cgatecron')) {
                $upperValues = array_column($cU, 'value');
                $lowerValues = array_column($cL, 'value');
                $maxUpper = max($upperValues);
                $minLower = min($lowerValues);
                $this->logger->info("Debug corridor for cgate__cgatecron: max upper=$maxUpper, min lower=$minLower");
            }
            
            // Агрегируем dataPoints на histStep перед расчетом аномалий
            $aggregatedOrig = [];
            $buckets = [];
            foreach ($orig as $point) {
                $bucketKey = floor($point['time'] / $histStep) * $histStep;
                if (!isset($buckets[$bucketKey])) {
                    $buckets[$bucketKey] = [];
                }
                $buckets[$bucketKey][] = $point['value'];
            }
            foreach ($buckets as $time => $values) {
                $avg = count($values) > 0 ? array_sum($values) / count($values) : 0;
                $aggregatedOrig[] = ['time' => $time, 'value' => $avg];
            }
            $this->logger->debug("Aggregated dataPoints for anomaly stats: before=" . count($orig) . ", after=" . count($aggregatedOrig) . ", histStep=$histStep");
            
            // аномалии (выровнять ряды по времени)
            $currStats = $this->anomalyDetector->calculateAnomalyStats(
                $this->alignByTime($aggregatedOrig, $cU, $cL),
                $cU, $cL,
                $this->config['corrdor_params']['default_percentiles'],
                true, // raw
                false, // not historical
                $histStep  // actual step for live, use histStep to match historical percentiles
            );
   
            // concern-метрики
            $wsize = $this->config['corrdor_params']['window_size'];
            $aboveConcerns = $this->anomalyDetector->calculateIntegralMetric(
                $currStats['above'], $cached['meta']['anomaly_stats']['above'] ?? []
            );
            $belowConcerns = $this->anomalyDetector->calculateIntegralMetric(
                $currStats['below'], $cached['meta']['anomaly_stats']['below'] ?? []
            );
            $aboveC = $aboveConcerns['total_concern'] / 10;
            $belowC = $belowConcerns['total_concern'] / 10;
            $this->logger->debug("CorridorBuilder: anomaly_concern_above = $aboveC, anomaly_concern_below = $belowC");
   
            $aboveSums = $this->anomalyDetector->calculateIntegralMetricSum(
                $currStats['above'], $cached['meta']['anomaly_stats']['above'] ?? [], $wsize
            );
            $belowSums = $this->anomalyDetector->calculateIntegralMetricSum(
                $currStats['below'], $cached['meta']['anomaly_stats']['below'] ?? [], $wsize
            );
            $aboveS = $aboveSums['total_concern_sum'] / 10;
            $belowS = $belowSums['total_concern_sum'] / 10;
   
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

            if (!empty($cached) && empty($orig)) {
                $item['nodata'] = [['time' => time(), 'value' => 1.0]];
                $this->logger->info("Added nodata flag for query: $query, labels: $labelsJson");
            }

            $results[] = $item;

            // Очистка памяти после обработки каждой метрики
            unset($orig, $cached, $upper, $lower, $cU, $cL, $aggregatedOrig, $buckets, $currStats, $item, $l1, $requestMd5, $l1Status);
        }

        PerformanceMonitor::end('total_processing');
        if (empty($results)) {
            $this->logger->warning("Empty results for query '$query' (fetch error or no metrics), attempting to format empty/nodata response");
        }
        return $this->responseFormatter->formatForGrafana($results, $query, $showMetrics);
    }

    /**
     * Выравнивает ряды по общим таймштампам для устранения предупреждений о длинах.
     */
    private function alignByTime(array $orig, array $u, array $l): array
    {
        $origTimes = array_column($orig, 'time');
        $uTimes = array_column($u, 'time');
        $lTimes = array_column($l, 'time');

        $commonTimes = array_intersect($origTimes, $uTimes, $lTimes);
        if (empty($commonTimes)) {
            return $orig; // fallback
        }

        $aligned = [];
        foreach ($orig as $point) {
            if (in_array($point['time'], $commonTimes)) {
                $aligned[] = $point;
            }
        }
        return $aligned;
    }

    /**
     * Вычисляет перцентиль массива значений.
     */
    private function getPercentile(array $values, float $p): float
    {
        $sorted = $values;
        sort($sorted);
        $n = count($sorted);
        if ($n == 0) return 0.0;
        $pos = $p * ($n - 1);
        $lower = (int)floor($pos);
        $upper = (int)ceil($pos);
        if ($lower == $upper) {
            return $sorted[$lower];
        }
        $weight = $pos - $lower;
        return $sorted[$lower] * (1 - $weight) + $sorted[$upper] * $weight;
    }

    /**
     * Применяет грязный хак к коридору: верхняя граница >= нижней, и обрабатывает нулевой коридор.
     */
    private function applyCorridorHack(array &$upper, array &$lower): void
    {
        $upperValues = array_column($upper, 'value');
        $lowerValues = array_column($lower, 'value');
        $dev_percentile_low = $this->config['corrdor_params']['dev_percentile_low'] ?? 0.495;
        $dev_percentile_high = $this->config['corrdor_params']['dev_percentile_high'] ?? 0.505;
        $p_low_upper = $this->getPercentile($upperValues, $dev_percentile_low);
        $p_high_upper = $this->getPercentile($upperValues, $dev_percentile_high);
        $dev_upper = max($p_high_upper - $p_low_upper, 0.01);
        $p_low_lower = $this->getPercentile($lowerValues, $dev_percentile_low);
        $p_high_lower = $this->getPercentile($lowerValues, $dev_percentile_high);
        $dev_lower = max($p_high_lower - $p_low_lower, 0.01);
        $factor_upper = $dev_upper;
        $factor_lower = $dev_lower;

        $n = count($upper);
        for ($i = 0; $i < $n; $i++) {
            $u = &$upper[$i]['value'];
            $l = &$lower[$i]['value'];
            if ($u < $l) {
                $diff = $l - $u;
                $adjustment_upper = $diff * $factor_upper;
                $adjustment_lower = $diff * $factor_lower;
                $u += $adjustment_upper;
                $l -= $adjustment_lower;
            } elseif ($u == $l) {
                // Найти предыдущую точку, где они не равны
                $found = false;
                for ($j = $i - 1; $j >= 0; $j--) {
                    if ($upper[$j]['value'] != $lower[$j]['value']) {
                        $u = $upper[$j]['value'];
                        $l = $lower[$j]['value'];
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    // Крайний случай: ищем вперед первую не нулевую разницу
                    for ($j = $i + 1; $j < $n; $j++) {
                        if ($upper[$j]['value'] != $lower[$j]['value']) {
                            if ($upper[$j]['value'] > $lower[$j]['value']) {
                                // Присваиваем всем предыдущим значения из этой точки
                                for ($k = 0; $k <= $i; $k++) {
                                    $upper[$k]['value'] = $upper[$j]['value'];
                                    $lower[$k]['value'] = $lower[$j]['value'];
                                }
                            } else {
                                // upper < lower, применить коррекцию
                                $diff = $lower[$j]['value'] - $upper[$j]['value'];
                                $adjustment_upper = $diff * $factor_upper;
                                $adjustment_lower = $diff * $factor_lower;
                                for ($k = 0; $k <= $i; $k++) {
                                    $upper[$k]['value'] = $upper[$j]['value'] + $adjustment_upper;
                                    $lower[$k]['value'] = $lower[$j]['value'] - $adjustment_lower;
                                }
                            }
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        // Крайний случай: присваиваем средние значения
                        $avg_u = array_sum($upperValues) / count($upperValues);
                        $avg_l = array_sum($lowerValues) / count($lowerValues);
                        $u = $avg_u;
                        $l = $avg_l;
                        // Продолжаем до тех пор, пока верхняя не станет больше нижней
                        if ($u <= $l) {
                            $u = $l + 0.01;
                        }
                    }
                }
            }
        }
    }
}
