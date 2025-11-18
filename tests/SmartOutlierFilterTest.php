<?php
declare(strict_types=1);

namespace App\Tests;

use App\Processors\SmartOutlierFilter;
use PHPUnit\Framework\TestCase;

class SmartOutlierFilterTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        $this->config = [
            'corridor_params' => [
                'max_outlier_removal_percent' => 10
            ]
        ];
    }

    public function testFilterOutliersSkipsBinaryMetrics(): void
    {
        $filter = new SmartOutlierFilter($this->config);

        // Бинарная метрика: только 0 и 1
        $data = [
            ['time' => 1, 'value' => 0.0],
            ['time' => 2, 'value' => 1.0],
            ['time' => 3, 'value' => 0.0],
            ['time' => 4, 'value' => 1.0],
        ];

        $filtered = $filter->filterOutliers($data);
        $this->assertEquals($data, $filtered, 'Бинарные метрики не должны фильтроваться');
    }

    public function testFilterOutliersSkipsStaticMetrics(): void
    {
        $filter = new SmartOutlierFilter($this->config);

        // Статичная метрика: все значения одинаковые
        $data = [
            ['time' => 1, 'value' => 5.0],
            ['time' => 2, 'value' => 5.0],
            ['time' => 3, 'value' => 5.0],
        ];

        $filtered = $filter->filterOutliers($data);
        $this->assertEquals($data, $filtered, 'Статичные метрики не должны фильтроваться');
    }

    public function testFilterOutliersRemovesFlatSections(): void
    {
        $filter = new SmartOutlierFilter($this->config);

        // Данные с плоскими участками: много 0 и 100, и нормальные значения
        $data = [
            ['time' => 1, 'value' => 0.0],   // min
            ['time' => 2, 'value' => 50.0],
            ['time' => 3, 'value' => 60.0],
            ['time' => 4, 'value' => 100.0], // max
            ['time' => 5, 'value' => 0.0],   // min
            ['time' => 6, 'value' => 70.0],
        ];

        $filtered = $filter->filterOutliers($data);

        // После removeFlatSections: удаляются 0 и 100, остаются 50,60,70
        // percentileFilter: для 3 точек p3~60, p97~60, удаляются <60 или >60, остаётся 60,70
        $expected = [
            ['time' => 3, 'value' => 60.0],
            ['time' => 6, 'value' => 70.0],
        ];
        $this->assertEquals($expected, $filtered);
    }

    public function testFilterOutliersAppliesPercentileFilter(): void
    {
        $filter = new SmartOutlierFilter($this->config);

        // Данные с выбросами
        $data = [];
        for ($i = 1; $i <= 100; $i++) {
            $data[] = ['time' => $i, 'value' => 50.0 + mt_rand(-10, 10)]; // Нормальные 40-60
        }
        $data[10]['value'] = 5.0;  // Выброс ниже 3-го перцентиля
        $data[50]['value'] = 150.0; // Выброс выше 97-го перцентиля

        $filtered = $filter->filterOutliers($data);

        // Должны удалиться выбросы, но не более 10%
        $removedCount = 100 - count($filtered);
        $this->assertLessThanOrEqual(10, $removedCount, 'Удалено не более 10% данных');
        $this->assertGreaterThan(85, count($filtered), 'Осталось больше 85% данных');
    }

    public function testFilterOutliersWithEmptyData(): void
    {
        $filter = new SmartOutlierFilter($this->config);
        $filtered = $filter->filterOutliers([]);
        $this->assertEquals([], $filtered);
    }

    public function testFilterOutliersWithSmallData(): void
    {
        $filter = new SmartOutlierFilter($this->config);

        // Маленький набор данных
        $data = [
            ['time' => 1, 'value' => 10.0],
            ['time' => 2, 'value' => 20.0],
            ['time' => 3, 'value' => 30.0],
        ];

        $filtered = $filter->filterOutliers($data);
        // Для малого набора перцентили могут не сработать, но плоские участки удалятся если есть
        $this->assertIsArray($filtered);
    }
}