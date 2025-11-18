<?php
declare(strict_types=1);

namespace App\Processors;

class SmartOutlierFilter
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Умная фильтрация выбросов: два прохода.
     *
     * @param array $historyData Исторические данные: [['time' => int, 'value' => float], ...]
     * @return array Отфильтрованные данные
     */
    public function filterOutliers(array $historyData): array
    {
        if (empty($historyData)) {
            return [];
        }

        // Проверка на дискретные метрики (<=2 уникальных значений)
        $values = array_column($historyData, 'value');
        $uniqueValues = array_unique($values);
        if (count($uniqueValues) <= 2) {
            return $historyData; // Не фильтровать бинарные/статичные метрики
        }

        // Первый проход: удаление плоских участков (всех min/max, если есть другие значения)
        $filtered = $this->removeFlatSections($historyData);

        // Второй проход: перцентильная фильтрация с ограничением
        $filtered = $this->percentileFilter($filtered);

        return $filtered;
    }

    /**
     * Первый проход: удаление плоских участков.
     * Удаляет все точки с value == min или value == max, если есть другие значения.
     */
    private function removeFlatSections(array $data): array
    {
        if (empty($data)) {
            return [];
        }

        $values = array_column($data, 'value');
        $min = min($values);
        $max = max($values);

        // Если все значения одинаковые, не удалять
        if ($min === $max) {
            return $data;
        }

        // Удалить все точки с min или max
        $filtered = array_filter($data, function ($point) use ($min, $max) {
            return $point['value'] !== $min && $point['value'] !== $max;
        });

        return array_values($filtered);
    }

    /**
     * Второй проход: перцентильная фильтрация с возрастным приоритетом.
     * Удаляет значения ниже lower_percentile и выше (100 - upper_percentile) перцентиля, но не более maxRemovalPercent%.
     * При превышении лимита приоритет старым точкам.
     */
    private function percentileFilter(array $data): array
    {
        if (empty($data)) {
            return [];
        }

        $values = array_column($data, 'value');
        sort($values);
        $n = count($values);

        // Рассчитать перцентили из config
        $lowerPercentile = $this->config['corridor_params']['lower_percentile'] ?? 3;
        $upperPercentile = $this->config['corridor_params']['upper_percentile'] ?? 3;

        $pLowerIndex = (int)ceil(($lowerPercentile / 100) * ($n - 1));
        $pUpperIndex = (int)floor(((100 - $upperPercentile) / 100) * ($n - 1));

        $p3 = $values[$pLowerIndex];
        $p97 = $values[$pUpperIndex];

        // Максимальный процент для удаления
        $maxRemovalPercent = $this->config['corridor_params']['max_outlier_removal_percent'] ?? 10;
        $maxToRemove = (int)ceil($n * $maxRemovalPercent / 100);

        // Найти выбросы с их характеристиками
        $outliers = [];
        $minTime = min(array_column($data, 'time'));
        $maxTime = max(array_column($data, 'time'));
        $timeRange = $maxTime - $minTime ?: 1;

        foreach ($data as $point) {
            if ($point['value'] < $p3 || $point['value'] > $p97) {
                // Экстремальность: расстояние от медианы
                $median = $values[(int)($n / 2)];
                $extremity = abs($point['value'] - $median);

                // Возрастной фактор: чем старше, тем выше приоритет на удаление
                $ageFactor = $this->config['corridor_params']['age_weight_factor'] ?? 0.2;
                $normalizedAge = ($maxTime - $point['time']) / $timeRange; // 0=новый, 1=старый
                $ageBonus = $normalizedAge * $ageFactor;

                // Итоговый скор: экстремальность + возрастной бонус
                $score = $extremity * (1 + $ageBonus);

                $outliers[] = [
                    'time' => $point['time'],
                    'score' => $score
                ];
            }
        }

        // Сортировать по убыванию скора (самые "плохие" сначала)
        usort($outliers, fn($a, $b) => $b['score'] <=> $a['score']);

        // Взять топ для удаления
        $toRemoveCount = min(count($outliers), $maxToRemove);
        $toRemove = array_column(array_slice($outliers, 0, $toRemoveCount), 'time');

        // Фильтровать данные
        $filtered = array_filter($data, function ($point) use ($toRemove) {
            return !in_array($point['time'], $toRemove);
        });

        return array_values($filtered);
    }
}