<?php
declare(strict_types=1);

namespace App\Processors;

class PostCorridorFilter {
    /**
     * Фильтрует выбросы из исторических данных на основе перцентилей.
     *
     * @param array $historyData Исторические данные: [['time' => int, 'value' => float], ...]
     * @param array $bounds Границы (не используются в фильтрации, но для совместимости)
     * @param float $lowerPercentile Нижний перцентиль для фильтрации (например, 5.0)
     * @param float $upperPercentile Верхний перцентиль для фильтрации (например, 95.0)
     * @return array Отфильтрованные данные: [['time' => int, 'value' => float], ...]
     */
    public static function filterOutliers(
        array $historyData,
        array $bounds,
        float $lowerPercentile = 5.0,
        float $upperPercentile = 95.0
    ): array {
        if (empty($historyData)) {
            return [];
        }

        // Извлекаем значения для расчета перцентилей
        $values = array_column($historyData, 'value');
        sort($values);
        $n = count($values);

        // Рассчитываем нижний перцентиль
        $lowerIndex = ($n - 1) * ($lowerPercentile / 100);
        $lowerValue = self::interpolatePercentile($values, $lowerIndex);

        // Рассчитываем верхний перцентиль
        $upperIndex = ($n - 1) * ($upperPercentile / 100);
        $upperValue = self::interpolatePercentile($values, $upperIndex);

        // Фильтруем данные
        $filtered = array_filter($historyData, function ($point) use ($lowerValue, $upperValue) {
            $value = $point['value'];
            return $value >= $lowerValue && $value <= $upperValue;
        });

        // Возвращаем как индексированный массив
        return array_values($filtered);
    }

    /**
     * Интерполирует значение перцентиля.
     *
     * @param array $sortedValues Отсортированный массив значений
     * @param float $index Индекс для интерполяции
     * @return float Интерполированное значение
     */
    private static function interpolatePercentile(array $sortedValues, float $index): float {
        $n = count($sortedValues);
        if ($n === 0) {
            return 0.0;
        }

        $lowerIndex = floor($index);
        $upperIndex = ceil($index);

        if ($lowerIndex === $upperIndex) {
            return $sortedValues[$lowerIndex];
        }

        if ($upperIndex >= $n) {
            return $sortedValues[$n - 1];
        }

        $fraction = $index - $lowerIndex;
        return $sortedValues[$lowerIndex] + $fraction * ($sortedValues[$upperIndex] - $sortedValues[$lowerIndex]);
    }
}
?>