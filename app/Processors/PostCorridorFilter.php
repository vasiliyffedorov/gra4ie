<?php
declare(strict_types=1);

namespace App\Processors;

class PostCorridorFilter {
    /**
     * Фильтрует выбросы из исторических данных, удаляя заданный процент самых низких и самых высоких значений.
     *
     * @param array $historyData Исторические данные: [['time' => int, 'value' => float], ...]
     * @param array $bounds Границы (опционально, не используются в фильтрации)
     * @param float $lowerPercentile Процент самых низких значений для удаления (например, 5.0)
     * @param float $upperPercentile Процент самых высоких значений для удаления (например, 5.0)
     * @return array Отфильтрованные данные: [['time' => int, 'value' => float], ...]
     */
    public static function filterOutliers(
        array $historyData,
        array $bounds = [],
        float $lowerPercentile = 5.0,
        float $upperPercentile = 5.0
    ): array {
        if (empty($historyData)) {
            return [];
        }

        // Создаем массив пар [value, time] для сортировки по значениям
        $pairs = [];
        foreach ($historyData as $point) {
            $pairs[] = [$point['value'], $point['time']];
        }

        // Сортируем по значениям по возрастанию
        usort($pairs, fn($a, $b) => $a[0] <=> $b[0]);

        $n = count($pairs);
        $lowerCount = (int)ceil($n * ($lowerPercentile / 100));
        $upperCount = (int)ceil($n * ($upperPercentile / 100));

        // Собираем таймстампы для удаления: первые lowerCount (самые низкие) и последние upperCount (самые высокие)
        $timestampsToRemove = [];
        for ($i = 0; $i < $lowerCount; $i++) {
            $timestampsToRemove[] = $pairs[$i][1];
        }
        for ($i = $n - $upperCount; $i < $n; $i++) {
            $timestampsToRemove[] = $pairs[$i][1];
        }

        // Удаляем точки с этими таймстампами
        $filtered = array_filter($historyData, function ($point) use ($timestampsToRemove) {
            return !in_array($point['time'], $timestampsToRemove);
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