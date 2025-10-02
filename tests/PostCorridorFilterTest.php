<?php
declare(strict_types=1);

namespace App\Tests;

use App\Processors\PostCorridorFilter;
use PHPUnit\Framework\TestCase;

class PostCorridorFilterTest extends TestCase
{
    public function testFilterOutliersRemovesSpikes(): void
    {
        // Создаем тестовые данные: стабильные значения около 100, с единичными всплесками
        $historyData = [];
        for ($i = 0; $i < 100; $i++) {
            $value = 100 + mt_rand(-10, 10); // Стабильные значения 90-110
            $historyData[] = ['time' => $i * 60, 'value' => $value];
        }
        // Добавляем всплески
        $historyData[10]['value'] = 1000; // Всплеск
        $historyData[50]['value'] = 1500; // Всплеск
        $historyData[80]['value'] = 2000; // Всплеск

        $bounds = []; // Не используется в фильтрации

        $filtered = PostCorridorFilter::filterOutliers($historyData, $bounds, 5.0, 95.0);

        // Проверяем, что всплески удалены
        $values = array_column($filtered, 'value');
        $maxValue = max($values);
        $this->assertLessThan(200, $maxValue, 'Всплески должны быть отфильтрованы');

        // Проверяем, что большинство данных осталось
        $this->assertGreaterThan(90, count($filtered), 'Большинство данных должно остаться');
    }

    public function testFilterOutliersWithNoOutliers(): void
    {
        // Данные без выбросов, все значения одинаковые
        $historyData = [];
        for ($i = 0; $i < 50; $i++) {
            $value = 100.0;
            $historyData[] = ['time' => $i * 60, 'value' => $value];
        }

        $bounds = [];
        $filtered = PostCorridorFilter::filterOutliers($historyData, $bounds, 5.0, 95.0);

        // Все данные должны остаться
        $this->assertEquals(count($historyData), count($filtered));
    }

    public function testFilterOutliersWithEmptyData(): void
    {
        $filtered = PostCorridorFilter::filterOutliers([], [], 5.0, 95.0);
        $this->assertEquals([], $filtered);
    }
}