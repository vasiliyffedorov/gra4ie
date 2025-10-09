<?php
declare(strict_types=1);

require_once 'app/Processors/CorridorBuilder.php';

// Тестовый класс для тестирования функции
class TestCorridorHack {
    public function applyCorridorHack(array &$upper, array &$lower): void {
        $n = count($upper);
        for ($i = 0; $i < $n; $i++) {
            $u = &$upper[$i]['value'];
            $l = &$lower[$i]['value'];
            if ($u < $l) {
                $diff = $l - $u;
                $u += 2 * $diff;
                $l -= $diff;
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
                                for ($k = 0; $k <= $i; $k++) {
                                    $upper[$k]['value'] += 2 * $diff;
                                    $lower[$k]['value'] -= $diff;
                                }
                            }
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        echo "All corridor points are equal, cannot fix zero corridor at index $i\n";
                    }
                }
            }
        }
    }
}

// Пример из задачи
$upper = [
    ['time' => 1, 'value' => 2],
    ['time' => 2, 'value' => 3],
    ['time' => 3, 'value' => 4],
    ['time' => 4, 'value' => 6],
    ['time' => 5, 'value' => 5],
    ['time' => 6, 'value' => 4],
    ['time' => 7, 'value' => 3],
    ['time' => 8, 'value' => 2],
    ['time' => 9, 'value' => 1],
    ['time' => 10, 'value' => 0],
    ['time' => 11, 'value' => -1],
    ['time' => 12, 'value' => -2],
    ['time' => 13, 'value' => -1],
    ['time' => 14, 'value' => 0],
];

$lower = [
    ['time' => 1, 'value' => 1],
    ['time' => 2, 'value' => 1],
    ['time' => 3, 'value' => 1],
    ['time' => 4, 'value' => 1],
    ['time' => 5, 'value' => 1],
    ['time' => 6, 'value' => 1],
    ['time' => 7, 'value' => 1],
    ['time' => 8, 'value' => 1],
    ['time' => 9, 'value' => 1],
    ['time' => 10, 'value' => 1],
    ['time' => 11, 'value' => 1],
    ['time' => 12, 'value' => 1],
    ['time' => 13, 'value' => 1],
    ['time' => 14, 'value' => 1],
];

echo "Before:\n";
echo "Upper: " . implode(',', array_column($upper, 'value')) . "\n";
echo "Lower: " . implode(',', array_column($lower, 'value')) . "\n";

$test = new TestCorridorHack();
$test->applyCorridorHack($upper, $lower);

echo "After:\n";
echo "Upper: " . implode(',', array_column($upper, 'value')) . "\n";
echo "Lower: " . implode(',', array_column($lower, 'value')) . "\n";