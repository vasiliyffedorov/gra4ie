<?php
declare(strict_types=1);

namespace App\Processors;

use App\Processors\AutoTunePeriodCalculator;

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
    private AutoTunePeriodCalculator $autoTune;
    private \App\Processors\HistoricalPeriodOptimizer $optimizer;

    public function __construct(
        array $config,
        LoggerInterface $logger,
        CacheManagerInterface $cacheManager,
        ResponseFormatter $responseFormatter,
        DataProcessorInterface $dataProcessor,
        DFTProcessorInterface $dftProcessor,
        AnomalyDetectorInterface $anomalyDetector,
        GrafanaClientInterface $client,
        AutoTunePeriodCalculator $autoTune,
        \App\Processors\HistoricalPeriodOptimizer $optimizer
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
        $this->autoTune = $autoTune;
        $this->optimizer = $optimizer;
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
        $this->optimizer->updateConfig($currentConfig);
        
        // Fetch history if empty or insufficient
        if (empty($historyData)) {
            $metricKey = $query . '|' . $labelsJson;
            $maxPeriodDays = $this->cacheManager->loadMaxPeriod($metricKey);
            if ($maxPeriodDays === null) {
                $maxPeriodDays = $this->optimizer->determineMaxPeriod($query, $labelsJson, $currentConfig['corrdor_params']['step']);
            }
            $periodSec = (int)($maxPeriodDays * 86400);
            $histEnd = time();
            $histStart = $histEnd - $periodSec;
            $longRaw = $this->client->queryRange($query, $histStart, $histEnd, $currentConfig['corrdor_params']['step']);
            $longGrouped = $this->dataProcessor->groupData($longRaw);
            $historyData = $longGrouped[$labelsJson] ?? [];
            $this->logger->info("Fetched adaptive history for $metricKey: $maxPeriodDays days, " . count($historyData) . " points");
        }

        // Рассчитываем DFT и статистики
        $range = $this->dataProcessor->getActualDataRange($historyData);
        $longStart = $range['start'];
        $longEnd = $range['end'];
        $longStep = $currentConfig['corrdor_params']['step'];

        $minPts = $currentConfig['corrdor_params']['min_data_points'];
        if (count($historyData) < $minPts) {
            $this->logger->warning("Недостаточно долгосрочных данных, fallback to live");
            if (!empty($liveData)) {
                $values = array_column($liveData, 'value');
                $mean = array_sum($values) / count($values);
                $variance = array_sum(array_map(fn($v) => pow($v - $mean, 2), $values)) / count($values);
                $stddev = sqrt($variance);
                $factor = $currentConfig['corrdor_params']['fallback_stddev_factor'] ?? 2.0;
                $halfWidth = $stddev * $factor;
                $payload = $this->buildPlaceholder($query, $labelsJson, $longStart, $longEnd, $longStep);
                $payload['meta']['orig_stddev'] = $stddev;
                $payload['dft_upper']['trend']['intercept'] = $mean + $halfWidth;
                $payload['dft_lower']['trend']['intercept'] = $mean - $halfWidth;
                $payload['meta']['fallback_live'] = true;
                $this->logger->info("Fallback corridor from live for $query: mean $mean ± $halfWidth (stddev $stddev)");
                return $payload;
            } else {
                $this->logger->warning("No live data for fallback, placeholder");
                return $this->buildPlaceholder($query, $labelsJson, $longStart, $longEnd, $longStep);
            }
        }

        // Автотюн периода, если достаточно данных
        $autotuned = false;
        $originalHistoryData = $historyData;
        $originalLongStart = $longStart;
        $originalLongEnd = $longEnd;
        if (count($historyData) >= 100) {
            $historicalAssoc = [];
            foreach ($historyData as $point) {
                $historicalAssoc[(int)$point['time']] = (float)$point['value'];
            }
            try {
                $optimal = $this->autoTune->calculateOptimalPeriod($historicalAssoc);
                $currentConfig['corrdor_params']['historical_period_days'] = $optimal;
                $this->logger->info("Автотюн использован для {$labelsJson}: период {$optimal} дней");

                // Подрезать historyData на новый период (удалить recent tail, оставить early head)
                $fullPeriodSec = (int)($optimal * 86400);
                $longEndNew = $originalLongEnd;
                $cutStart = $longEndNew - $fullPeriodSec;
                $historyData = array_filter($originalHistoryData, fn($point) => $point['time'] >= $cutStart);
                usort($historyData, fn($a, $b) => $a['time'] <=> $b['time']);
                if (count($historyData) < $minPts) {
                    $this->logger->warning("Недостаточно данных после автотюна подрезки, fallback на оригинал");
                    $historyData = $originalHistoryData;
                    $longStart = $originalLongStart;
                    $longEnd = $originalLongEnd;
                } else {
                    $autotuned = true;
                    $range = $this->dataProcessor->getActualDataRange($historyData);
                    $longStart = $range['start'];
                    $longEnd = $range['end'];
                    $this->logger->info("Автотюн подрезка для {$labelsJson}: период {$optimal} дней, точек с " . count($originalHistoryData) . " до " . count($historyData));
                }
            } catch (\InvalidArgumentException $e) {
                $this->logger->warning("Автотюн не удался для {$labelsJson}: " . $e->getMessage());
            }
        }

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

        // по умолчанию — скейла нет
        $scaleCorridor = false;

        if ($countS === 0 || $avgS100 <= 0.0 || $minTs === null) {
            $this->logger->info("Autoscale(k={$k}) insufficient S data for {$labelsJson}: countS={$countS}, avgS100={$avgS100}");
        } else {
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
