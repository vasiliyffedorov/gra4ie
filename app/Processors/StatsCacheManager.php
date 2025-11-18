<?php
declare(strict_types=1);

namespace App\Processors;

use App\Processors\SmartOutlierFilter;
use App\Utilities\CacheHelpers;

use App\Interfaces\LoggerInterface;
use App\Interfaces\CacheManagerInterface;
use App\Interfaces\GrafanaClientInterface;
use App\Formatters\ResponseFormatter;
use App\Interfaces\DataProcessorInterface;
use App\Interfaces\DFTProcessorInterface;
use App\Interfaces\AnomalyDetectorInterface;

class StatsCacheManager {
    use CacheHelpers;

    private array $config;
    private LoggerInterface $logger;
    private CacheManagerInterface $cacheManager;
    private ResponseFormatter $responseFormatter;
    private DataProcessorInterface $dataProcessor;
    private DFTProcessorInterface $dftProcessor;
    private AnomalyDetectorInterface $anomalyDetector;
    private GrafanaClientInterface $client;
    private \App\Processors\HistoricalPeriodOptimizer $optimizer;
    private SmartOutlierFilter $smartOutlierFilter;

    public function __construct(
        array $config,
        LoggerInterface $logger,
        CacheManagerInterface $cacheManager,
        ResponseFormatter $responseFormatter,
        DataProcessorInterface $dataProcessor,
        DFTProcessorInterface $dftProcessor,
        AnomalyDetectorInterface $anomalyDetector,
        GrafanaClientInterface $client,
        \App\Processors\HistoricalPeriodOptimizer $optimizer
    ) {
        if (!isset($config['corridor_params']['step']) || !isset($config['cache'])) {
            throw new \InvalidArgumentException('Config must contain corridor_params.step and cache');
        }
        $this->config = $config;
        $this->logger = $logger;
        $this->cacheManager = $cacheManager;
        $this->responseFormatter = $responseFormatter;
        $this->dataProcessor = $dataProcessor;
        $this->dftProcessor = $dftProcessor;
        $this->anomalyDetector = $anomalyDetector;
        $this->client = $client;
        $this->optimizer = $optimizer;
        $this->smartOutlierFilter = new SmartOutlierFilter($config);
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
        if (!isset($currentConfig['corridor_params']['step'])) {
            throw new \InvalidArgumentException('Config must contain corridor_params.step');
        }

        // Если метрика помечена как unused — ничего не делаем
        $cached = $this->cacheManager->loadFromCache($query, $labelsJson);
        if (isset($cached['meta']['labels']['unused_metric'])) {
            $this->logger->info("Пропуск пересчета unused_metric");
            return $cached;
        }

        // Если кеш существует, пропустить оптимизацию периода и fetch, использовать кеш
        if ($cached !== null && !$this->cacheManager->shouldRecreateCache($query, $labelsJson, $currentConfig)) {
            $this->logger->info("Cache HIT: skipping period optimization and fetch for {$query}, {$labelsJson}");
            return $this->buildCorridorAndGetPayload($query, $labelsJson, $historyData, $currentConfig, 0); // optimalPeriodDays не важен, так как кеш используется
        }

        // L1 cache integration
        $requestMd5 = $this->client->getNormalizedRequestMd5($query) ?: '';
        $l1 = $this->cacheManager->loadMetricsCacheL1($query, $labelsJson);
        $l1Status = 'MISS';
        if ($l1) {
            if ($l1['request_md5'] === $requestMd5) {
                $l1Status = 'HIT';
                $this->logger->info("L1 HIT: query={$query}, labels={$labelsJson}, md5={$requestMd5}, period={$l1['optimal_period_days']}, scale={$l1['scale_corridor']}, k={$l1['k']}, factor={$l1['factor']}");
            } else {
                $l1Status = 'STALE';
                $this->logger->info("L1 STALE: query={$query}, labels={$labelsJson}, old={$l1['request_md5']}, new={$requestMd5}, action=force_recalc");
            }
        } else {
            $this->logger->info("L1 MISS: query={$query}, labels={$labelsJson}, md5={$requestMd5}");
        }

        // Обновляем конфиг в процессорах
        $this->dataProcessor->updateConfig($currentConfig);
        $this->anomalyDetector->updateConfig($currentConfig);
        $this->optimizer->updateConfig($currentConfig);

        // Determine optimal period first
        $metricKey = $query . '|' . $labelsJson;
        $l1Data = $this->cacheManager->loadMetricsCacheL1($query, $labelsJson);
        $optimalPeriodDays = $l1Data['optimal_period_days'] ?? null;
        $this->logger->info("Checked permanent cache for $metricKey: optimal_period_days=" . ($optimalPeriodDays ?? 'null'));

        if ($optimalPeriodDays !== null) {
            $maxPeriodDays = $optimalPeriodDays;
            $this->logger->info("Using optimal period from permanent cache for $metricKey: $maxPeriodDays days");
        } else {
            $maxPeriodDays = $this->optimizer->determineMaxPeriod($query, $labelsJson, $currentConfig['corridor_params']['step']);
            $this->logger->info("Permanent cache empty for $metricKey, using optimized period from HistoricalPeriodOptimizer: $maxPeriodDays days");
        }

        // Fetch history if empty or insufficient duration
        $needFetch = empty($historyData);
        if (!$needFetch) {
            $range = $this->dataProcessor->getActualDataRange($historyData);
            $providedDurationDays = ($range['end'] - $range['start']) / 86400;
            $this->logger->info("Provided historyData duration: {$providedDurationDays} days, points: " . count($historyData));
            if ($providedDurationDays < $maxPeriodDays) {
                $this->logger->info("Provided data shorter than optimal period {$maxPeriodDays} days, fetching additional data");
                $needFetch = true;
            }
        }
        $longGrouped = [];
        if ($needFetch) {
            $periodSec = (int)($maxPeriodDays * 86400);
            $histEnd = time();
            $histStart = $histEnd - $periodSec;
            $this->logger->info("Fetching additional data for $metricKey: period {$maxPeriodDays} days, from {$histStart} to {$histEnd}");
            $longRaw = $this->client->queryRange($query, $histStart, $histEnd, $currentConfig['corridor_params']['step']);
            $longGrouped = $this->dataProcessor->groupData($longRaw);
            $fetchedData = $longGrouped[$labelsJson] ?? [];
            $this->logger->info("Fetched adaptive history for $metricKey: $maxPeriodDays days, " . count($fetchedData) . " points");

            // Process all metrics from the fetch result
            foreach ($longGrouped as $otherLabelsJson => $otherFetchedData) {
                if ($otherLabelsJson === $labelsJson) {
                    continue; // Skip current metric, will be processed below
                }
                $otherCached = $this->cacheManager->loadFromCache($query, $otherLabelsJson);
                if ($otherCached === null) {
                    $this->logger->info("Processing additional metric from fetch: {$query}, {$otherLabelsJson}");
                    $this->buildCorridorAndSave($query, $otherLabelsJson, $otherFetchedData, $currentConfig, $maxPeriodDays, $l1Status, $l1, 8, null, false);
                }
            }

            if (empty($historyData)) {
                $historyData = $fetchedData;
                $this->logger->info("Using fetched data as historyData: " . count($historyData) . " points");
            } else {
                $originalCount = count($historyData);
                // Merge with provided data
                $historyData = array_merge($historyData, $fetchedData);
                $historyData = array_unique($historyData, SORT_REGULAR); // Remove duplicates if any
                usort($historyData, fn($a, $b) => $a['time'] <=> $b['time']);
                $addedPoints = count($historyData) - $originalCount;
                $this->logger->info("Merged historyData: original {$originalCount} points, added {$addedPoints} points, total " . count($historyData));
            }
        }

        // Build corridor for the current metric
        return $this->buildCorridorAndGetPayload($query, $labelsJson, $historyData, $currentConfig, $maxPeriodDays, $l1Status, $l1);
    }

    /**
     * Строит коридор, гармоники, аномалии и сохраняет в кеш для заданной метрики.
     *
     * @param string $query Запрос для Grafana
     * @param string $labelsJson JSON-строка с лейблами метрики
     * @param array $historyData Исторические данные: [['time' => int, 'value' => float], ...]
     * @param array $currentConfig Текущая конфигурация
     * @param float $optimalPeriodDays Оптимальный период в днях
     * @param string $l1Status Статус L1 кеша
     * @param array $l1 Данные L1 кеша
     * @param int $k Коэффициент масштабирования
     * @param float|null $factor Фактор масштабирования
     * @param bool $scaleCorridor Флаг масштабирования коридора
     */
    private function buildCorridorAndSave(
        string $query,
        string $labelsJson,
        array $historyData,
        array $currentConfig,
        float $optimalPeriodDays,
        string $l1Status,
        ?array $l1,
        int $k,
        ?float $factor,
        bool $scaleCorridor
    ): void {
        $this->buildCorridorAndGetPayload($query, $labelsJson, $historyData, $currentConfig, $optimalPeriodDays, $l1Status, $l1, $k, $factor, $scaleCorridor);
    }

    /**
     * Строит коридор, гармоники, аномалии, сохраняет в кеш и возвращает payload.
     *
     * @param string $query Запрос для Grafana
     * @param string $labelsJson JSON-строка с лейблами метрики
     * @param array $historyData Исторические данные: [['time' => int, 'value' => float], ...]
     * @param array $currentConfig Текущая конфигурация
     * @param float $optimalPeriodDays Оптимальный период в днях
     * @return array Payload
     */
    private function buildCorridorAndGetPayload(
        string $query,
        string $labelsJson,
        array $historyData,
        array $currentConfig,
        float $optimalPeriodDays,
        ?string $l1Status = null,
        ?array $l1 = null,
        int $k = 8,
        ?float $factor = null,
        bool $scaleCorridor = false
    ): array {
        // L1 cache integration for this metric (skip if cache HIT)
        if ($l1Status === null || $l1 === null) {
            $requestMd5 = $this->client->getNormalizedRequestMd5($query) ?: '';
            $l1 = $this->cacheManager->loadMetricsCacheL1($query, $labelsJson);
            $l1Status = 'MISS';
            if ($l1) {
                if ($l1['request_md5'] === $requestMd5) {
                    $l1Status = 'HIT';
                    $this->logger->info("L1 HIT: query={$query}, labels={$labelsJson}, md5={$requestMd5}, period={$l1['optimal_period_days']}, scale={$l1['scale_corridor']}, k={$l1['k']}, factor={$l1['factor']}");
                } else {
                    $l1Status = 'STALE';
                    $this->logger->info("L1 STALE: query={$query}, labels={$labelsJson}, old={$l1['request_md5']}, new={$requestMd5}, action=force_recalc");
                }
            } else {
                $this->logger->info("L1 MISS: query={$query}, labels={$labelsJson}, md5={$requestMd5}");
            }
        }

        // Умная фильтрация выбросов перед расчётом границ
        $originalHistoryData = $historyData;
        $historyData = $this->smartOutlierFilter->filterOutliers($historyData);
        $this->logger->info("Smart outlier filter: original " . count($originalHistoryData) . " points, filtered " . count($historyData) . " points for {$query}, {$labelsJson}");

        // Рассчитываем DFT и статистики
        $range = $this->dataProcessor->getActualDataRange($historyData);
        $longStart = $range['start'];
        $longEnd = $range['end'];
        $longStep = $currentConfig['corridor_params']['step'];


        // Проверить необходимость пересоздания кеша DFT
        $cached = $this->cacheManager->loadFromCache($query, $labelsJson);
        if ($cached !== null && !$this->cacheManager->shouldRecreateCache($query, $labelsJson, $currentConfig)) {
            $this->logger->info("Cache HIT: using cached DFT for corridor restoration on current period for {$query}, {$labelsJson}");
            // Восстановить коридор на текущий период запроса
            $meta = $cached['meta'];
            $meta['dataStart'] = $longStart;
            $meta['totalDuration'] = $longEnd - $longStart;
            $meta['created_at'] = time(); // обновить время
            $meta['dft_rebuild_count'] = ($meta['dft_rebuild_count'] ?? 0) + 1; // возможно не увеличивать, но для совместимости

            $upperSeries = $this->dftProcessor->restoreFullDFT(
                $cached['dft_upper']['coefficients'],
                $longStart, $longEnd, $longStep,
                $meta, $cached['dft_upper']['trend']
            );
            $lowerSeries = $this->dftProcessor->restoreFullDFT(
                $cached['dft_lower']['coefficients'],
                $longStart, $longEnd, $longStep,
                $meta, $cached['dft_lower']['trend']
            );

            // Рассчитать статистики аномалий на текущих данных
            $stats = $this->anomalyDetector->calculateAnomalyStats(
                $historyData, $upperSeries, $lowerSeries,
                $currentConfig['corridor_params']['default_percentiles'],
                false, // raw
                true, // isHistorical
                $longStep // actual step for hist
            );
            $meta['anomaly_stats'] = $stats;

            // Вычислить сдвиги
            [$upperShift, $lowerShift] = $this->calculateShifts(
                $historyData, [
                    'upper' => $cached['dft_upper'],
                    'lower' => $cached['dft_lower']
                ], $longStart, $longEnd, $longStep, $meta, $currentConfig
            );

            return [
                'meta' => $meta,
                'dft_upper' => $cached['dft_upper'],
                'dft_lower' => $cached['dft_lower'],
                'upper_shift' => $upperShift,
                'lower_shift' => $lowerShift,
            ];
        }
        $this->logger->info("Cache MISS: recalculating corridor for {$query}, {$labelsJson}");

        // Определение периода: использовать из L1 если HIT, иначе optimalPeriodDays
        $originalHistoryData = $historyData;
        $originalLongStart = $longStart;
        $originalLongEnd = $longEnd;
        if ($l1Status === 'HIT' && $l1['optimal_period_days'] !== null) {
            $optimalPeriodDays = $l1['optimal_period_days'];
            $this->logger->info("Используем период из L1 HIT для {$labelsJson}: {$optimalPeriodDays} дней");
            // Подрезать historyData на период из L1
            $fullPeriodSec = (int)($optimalPeriodDays * 86400);
            $longEndNew = $originalLongEnd;
            $cutStart = $longEndNew - $fullPeriodSec;
            $this->logger->info("L1 trimming debug: longStart={$originalLongStart}, longEnd={$originalLongEnd}, fullPeriodSec={$fullPeriodSec}, cutStart={$cutStart}, originalCount=" . count($originalHistoryData));
            $historyData = array_filter($historyData, fn($point) => $point['time'] >= $cutStart);
            usort($historyData, fn($a, $b) => $a['time'] <=> $b['time']);
            $this->logger->info("After L1 trimming: count=" . count($historyData));
            $range = $this->dataProcessor->getActualDataRange($historyData);
            $longStart = $range['start'];
            $longEnd = $range['end'];
            $this->logger->info("Подрезка по L1 периоду для {$labelsJson}: {$optimalPeriodDays} дней, точек с " . count($originalHistoryData) . " до " . count($historyData));
            if ($optimalPeriodDays <= 7) {
                $this->logger->info("Short period (<=7d) trimming applied for {$labelsJson}");
            }
        } else {
            $this->logger->info("Using optimalPeriodDays for {$labelsJson}: {$optimalPeriodDays} days");

            // Подрезать historyData на optimalPeriodDays
            $fullPeriodSec = (int)($optimalPeriodDays * 86400);
            $longEndNew = $originalLongEnd;
            $cutStart = $longEndNew - $fullPeriodSec;
            $this->logger->info("Optimizer trimming debug: longStart={$originalLongStart}, longEnd={$originalLongEnd}, fullPeriodSec={$fullPeriodSec}, cutStart={$cutStart}, originalCount=" . count($originalHistoryData));
            $historyData = array_filter($historyData, fn($point) => $point['time'] >= $cutStart);
            usort($historyData, fn($a, $b) => $a['time'] <=> $b['time']);
            $this->logger->info("After optimizer trimming: count=" . count($historyData));
            $range = $this->dataProcessor->getActualDataRange($historyData);
            $longStart = $range['start'];
            $longEnd = $range['end'];
            $this->logger->info("Optimizer подрезка для {$labelsJson}: период {$optimalPeriodDays} дней, точек с " . count($originalHistoryData) . " до " . count($historyData));
            if ($optimalPeriodDays <= 7) {
                $this->logger->info("Short period (<=7d) optimizer trimming applied for {$labelsJson}");
            }
        }

        // Добавить выбранный период в config
        $currentConfig['historical_period_days'] = $optimalPeriodDays;
        unset($currentConfig['historical_period_days']);


        // Автоматическое определение необходимости масштабирования (обобщённый алгоритм по последним 100 ненулевым точкам)
        // 1) Берём последние 100 ненулевых/не-NaN точек из historyData на шаге S = $longStep
        $k = 8; // коэффициент масштабирования шага: проверяем S/k
        $S = max(1, (int)$longStep); // сек
        $Sdiv = max(1, (int)floor($S / $k)); // шаг S/k в секундах, минимум 1
        
        // отфильтруем валидные ненулевые точки
        $nonZero = array_values(array_filter($historyData, function ($p) {
            if (!isset($p['value'])) return false;
            $v = (float)$p['value'];
            return is_finite($v) && $v != 0.0;
        }));
        // отсортируем по времени (на всякий случай)
        usort($nonZero, fn($a, $b) => ($a['time'] <=> $b['time']));
        // последние 100 ненулевых
        $tail = array_slice($nonZero, -100);
        $countS = count($tail);
        $avgS100 = $countS > 0 ? (array_sum(array_column($tail, 'value')) / $countS) : 0.0;
        $minTs = $countS > 0 ? min(array_column($tail, 'time')) : null;

        // по умолчанию — скейла нет (применить HIT если есть)
        $scaleCorridor = false;
        $factor = null;
        if ($l1Status === 'HIT' && $l1['scale_corridor'] !== null) {
            $scaleCorridor = $l1['scale_corridor'];
            $factor = $l1['factor'];
            $this->logger->info("Autoscale из L1 HIT для {$labelsJson}: scale={$scaleCorridor}, factor=" . ($factor ?? 'null'));
        } elseif (($l1Status === 'MISS' || $l1Status === 'STALE') && $countS > 0 && $avgS100 > 0.0 && $minTs !== null) {
            // 2) Выравниваем границы для окна проверки и запрашиваем ряд на шаге S/k
            // from_aligned = ceil(min_ts / S) * S; to_aligned = $longEnd (он уже привязан к текущему диапазону)
            $fromAligned = (int)(ceil($minTs / $S) * $S);
            $toAligned = (int)$longEnd;
            if ($fromAligned >= $toAligned) {
                // если окно выродилось — отступим на один S назад
                $fromAligned = max($longStart, $toAligned - $S);
            }

            // запрос с шагом S/k
            $rawDiv = $this->client->queryRange($query, $fromAligned, $toAligned, $Sdiv);
            $grpDiv = $this->dataProcessor->groupData($rawDiv);
            $dataDiv = $grpDiv[$labelsJson] ?? [];

            // для S/k исключаем только null/NaN; нули допускаются для delta-метрик
            $validDiv = array_values(array_filter($dataDiv, function ($p) {
                if (!isset($p['value'])) return false;
                $v = (float)$p['value'];
                return is_finite($v);
            }));
            $countSdiv = count($validDiv);
            $avgSdiv = $countSdiv > 0 ? (array_sum(array_column($validDiv, 'value')) / $countSdiv) : 0.0;

            if ($avgSdiv > 0.0) {
                // 3) Сравнение: factor = avg(S/k) / avg(S_100)
                $factor = $avgSdiv / $avgS100;

                // Цели: rate-поведение ~1.0, delta-поведение ~1/k
                $targetRate  = 1.0;
                $targetDelta = 1.0 / $k;
                $relTol = 0.2; // 20% относительная толерантность
                $isClose = function (float $x, float $target, float $tol): bool {
                    return abs($x - $target) <= $tol * max($target, 1e-12);
                };

                $decisionReason = 'undetermined';
                if ($isClose($factor, $targetRate, $relTol)) {
                    // rate-like — масштабирование не требуется
                    $scaleCorridor = false;
                    $decisionReason = 'factor≈1 (rate-like)';
                } elseif ($isClose($factor, $targetDelta, $relTol)) {
                    // delta-like — масштабирование требуется
                    $scaleCorridor = true;
                    $decisionReason = 'factor≈1/k (delta-like)';
                } else {
                    // если не попали в коридор толерантности, выбираем ближайшую цель
                    $dRate  = abs($factor - $targetRate);
                    $dDelta = abs($factor - $targetDelta);
                    $scaleCorridor = ($dDelta < $dRate); // ближе к 1/k => включаем скейл
                    $decisionReason = $scaleCorridor ? 'closer_to_1/k' : 'closer_to_1';
                }

                $this->logger->info(
                    "Autoscale(k={$k}) for {$labelsJson}: "
                    . "S.count={$countS}, S.avg100={$avgS100}, "
                    . "win=[{$fromAligned},{$toAligned}], Sdiv={$Sdiv}, "
                    . "S/k.count={$countSdiv}, S/k.avg={$avgSdiv}, factor={$factor}, "
                    . "decision=" . ($scaleCorridor ? 'true' : 'false') . " ({$decisionReason})"
                );
            } else {
                $this->logger->info(
                    "Autoscale(k={$k}) insufficient S/k data for {$labelsJson}: "
                    . "S.count={$countS}, S.avg100={$avgS100}, S/k.count={$countSdiv}, S/k.avg={$avgSdiv}"
                );
            }
        }
        
        // Генерируем DFT
        $bounds = $this->dataProcessor->calculateBounds($historyData, $longStart, $longEnd, $longStep);

        $dftResult = $this->dftProcessor->generateDFT($bounds, $longStart, $longEnd, $longStep);

        // Debug: log bounds for cgate__cgatecron
        if (str_contains($query, 'cgate__cgatecron')) {
            $upperBounds = array_column($bounds['upper'], 'value');
            $lowerBounds = array_column($bounds['lower'], 'value');
            $maxUpperBound = max($upperBounds);
            $minLowerBound = min($lowerBounds);
            $this->logger->info("Debug bounds for cgate__cgatecron: max upper bound=$maxUpperBound, min lower bound=$minLowerBound");
        }

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
        $minAmp = (float)($currentConfig['corridor_params']['min_amplitude'] ?? 1e-12);
        $dftResult['upper']['coefficients'] = array_filter(
            $dftResult['upper']['coefficients'],
            fn($c) => $c['amplitude'] >= $minAmp
        );
        $dftResult['lower']['coefficients'] = array_filter(
            $dftResult['lower']['coefficients'],
            fn($c) => $c['amplitude'] >= $minAmp
        );

        // Восстанавливаем траектории
        $meta = [
            'dataStart'         => $longStart,
            'step'              => $longStep,
            'totalDuration'     => $longEnd - $longStart,
            'config_hash'       => $this->createConfigHash($this->config),
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
            $currentConfig['corridor_params']['default_percentiles'],
            false, // raw
            true, // isHistorical
            $longStep // actual step for hist
        );
        $meta['anomaly_stats'] = $stats;

        // Вычисляем сдвиги для коррекции ширины коридора
        [$upperShift, $lowerShift] = $this->calculateShifts(
            $historyData, $dftResult, $longStart, $longEnd, $longStep, $meta, $currentConfig
        );

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
            'upper_shift' => $upperShift,
            'lower_shift' => $lowerShift,
        ];

        // Сохраняем в кэш
        $this->cacheManager->saveToCache($query, $labelsJson, $payload, $currentConfig);

        // Сохраняем в L1 только при MISS, STALE или если scale изменился
        if ($l1Status === 'MISS' || $l1Status === 'STALE' || ($scaleCorridor !== ($l1['scale_corridor'] ?? null))) {
            $this->cacheManager->saveMetricsCacheL1($query, $labelsJson, [
                'request_md5' => $requestMd5,
                'optimal_period_days' => $optimalPeriodDays,
                'scale_corridor' => $scaleCorridor,
                'k' => $k,
                'factor' => $factor,
            ]);
        }

        return $payload;
    }

    /**
     * Вычисляет сдвиги для коррекции ширины коридора на основе исторических данных.
     *
     * @param array $historyData Исторические данные [['time' => int, 'value' => float], ...]
     * @param array $dftResult Результат DFT с коэффициентами и трендами
     * @param int $longStart Начало периода
     * @param int $longEnd Конец периода
     * @param int $longStep Шаг
     * @param array $meta Мета-данные
     * @param array $currentConfig Текущая конфигурация
     * @return array [upper_shift, lower_shift]
     */
    private function calculateShifts(
        array $historyData,
        array $dftResult,
        int $longStart,
        int $longEnd,
        int $longStep,
        array $meta,
        array $currentConfig
    ): array {
        $minCorridorWidthFactor = $currentConfig['corridor_params']['min_corridor_width_factor'] ?? 0.05; // default 5% of stddev or something

        // Восстанавливаем коридор на исторический период
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

        // Создаем ассоциативные массивы для быстрого доступа
        $upperMap = [];
        foreach ($upperSeries as $point) {
            $upperMap[$point['time']] = $point['value'];
        }
        $lowerMap = [];
        foreach ($lowerSeries as $point) {
            $lowerMap[$point['time']] = $point['value'];
        }

        $maxUpperShift = 0.0;
        $maxLowerShift = 0.0;

        foreach ($historyData as $point) {
            $time = $point['time'];
            $value = $point['value'];

            if (!isset($upperMap[$time]) || !isset($lowerMap[$time])) {
                continue;
            }

            $upper = $upperMap[$time];
            $lower = $lowerMap[$time];

            // Проверяем ширину
            if ($upper <= $lower + $minCorridorWidthFactor) {
                // Ширина недостаточна, нужно сдвинуть
                $mid = ($upper + $lower) / 2;
                if ($value > $mid) {
                    // Метрика выше середины, сдвигаем upper вверх
                    $shift = $minCorridorWidthFactor - ($upper - $lower);
                    $maxUpperShift = max($maxUpperShift, $shift);
                } else {
                    // Метрика ниже середины, сдвигаем lower вниз
                    $shift = $minCorridorWidthFactor - ($upper - $lower);
                    $maxLowerShift = max($maxLowerShift, $shift);
                }
            }
        }

        return [$maxUpperShift, $maxLowerShift];
    }



}
?>
