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
     * Второй проход: перцентильная фильтрация.
     * Удаляет значения ниже 3-го и выше 97-го перцентиля, но не более maxRemovalPercent%.
     */
    private function percentileFilter(array $data): array
    {
        if (empty($data)) {
            return [];
        }

        $values = array_column($data, 'value');
        sort($values);
        $n = count($values);

        // Рассчитать 3-й и 97-й перцентили
        $p3Index = (int)ceil(0.03 * ($n - 1));
        $p97Index = (int)floor(0.97 * ($n - 1));
        $p3 = $values[$p3Index];
        $p97 = $values[$p97Index];

        // Максимальный процент для удаления
        $maxRemovalPercent = $this->config['corridor_params']['max_outlier_removal_percent'] ?? 10;
        $maxToRemove = (int)ceil($n * $maxRemovalPercent / 100);

        // Найти точки для удаления
        $toRemove = [];
        foreach ($data as $point) {
            if ($point['value'] < $p3 || $point['value'] > $p97) {
                $toRemove[] = $point['time'];
            }
        }

        // Ограничить количество удалений
        if (count($toRemove) > $maxToRemove) {
            // Удалить только самые экстремальные (первые и последние в отсортированном списке)
            $sortedToRemove = $toRemove;
            sort($sortedToRemove);
            $toRemove = array_slice($sortedToRemove, 0, $maxToRemove);
        }

        // Фильтровать данные
        $filtered = array_filter($data, function ($point) use ($toRemove) {
            return !in_array($point['time'], $toRemove);
        });

        return array_values($filtered);
    }
}