<?php
declare(strict_types=1);

namespace App\Tests;

use App\Processors\NUDiscreteFourierTransformer;
use App\Utilities\Logger;
use PHPUnit\Framework\TestCase;

class NUDiscreteFourierTransformerTest extends TestCase
{
    private Logger $logger;
    private NUDiscreteFourierTransformer $transformer;

    protected function setUp(): void
    {
        $this->logger = new Logger('php://memory', Logger::LEVEL_INFO);
        $this->transformer = new NUDiscreteFourierTransformer($this->logger);
    }

    public function testCalculateDFTWithSparseData(): void
    {
        // Создаем прореженные данные: стабильные значения с пропусками
        $values = [100, 105, 102, 98, 101, 99, 103, 97, 104, 96];
        $times = [0, 60, 120, 180, 240, 300, 360, 420, 480, 540]; // Равномерные времена

        // Добавляем пропуски, имитируя фильтрацию
        $sparseValues = [100, 102, 101, 103, 104]; // Удалены некоторые точки
        $sparseTimes = [0, 120, 240, 360, 480]; // Соответствующие времена

        $maxHarmonics = 5;
        $totalDuration = 540;
        $numPoints = count($sparseValues);

        $coefficients = $this->transformer->calculateDFT($sparseValues, $sparseTimes, $maxHarmonics, $totalDuration, $numPoints);

        // Проверяем, что коэффициенты рассчитаны
        $this->assertIsArray($coefficients);
        $this->assertArrayHasKey(0, $coefficients); // Постоянная компонента

        // Проверяем, что амплитуды разумны
        foreach ($coefficients as $k => $coeff) {
            $this->assertGreaterThanOrEqual(0, $coeff['amplitude']);
            $this->assertIsFloat($coeff['phase']);
        }
    }

    public function testCalculateDFTValue(): void
    {
        $coefficients = [
            0 => ['amplitude' => 100.0, 'phase' => 0.0],
            1 => ['amplitude' => 5.0, 'phase' => 0.0],
        ];

        $normalizedTime = 0.5; // Половина периода
        $periodSeconds = 3600;

        $value = $this->transformer->calculateDFTValue($coefficients, $normalizedTime, $periodSeconds);

        // Значение должно быть близко к базовому
        $this->assertGreaterThanOrEqual(90, $value);
        $this->assertLessThanOrEqual(110, $value);
    }

    public function testCalculateHarmonicContributions(): void
    {
        $coefficients = [
            0 => ['amplitude' => 100.0, 'phase' => 0.0],
            1 => ['amplitude' => 10.0, 'phase' => 0.0],
        ];
        $times = [0, 60, 120, 180, 240];
        $totalDuration = 300;
        $numPoints = 5;

        $contributions = $this->transformer->calculateHarmonicContributions($coefficients, $times, $totalDuration, $numPoints);

        $this->assertIsArray($contributions);
        $this->assertArrayHasKey(0, $contributions);
        $this->assertArrayHasKey(1, $contributions);

        // Вклад постоянной компоненты должен быть большим
        $this->assertGreaterThan($contributions[1], $contributions[0]);
    }

    protected function tearDown(): void
    {
        unset($this->transformer, $this->logger);
    }

    public function testCalculateDFTWithIrregularHoles(): void
    {
        // Тест с нерегулярными дырами в данных (неравномерные пропуски)
        $values = [100, 105, 98, 103, 97, 104, 96, 102, 99, 101];
        $times = [0, 60, 180, 240, 360, 480, 600, 720, 900, 960]; // Нерегулярные времена с дырами

        $maxHarmonics = 5;
        $totalDuration = 960;
        $numPoints = count($values);

        $coefficients = $this->transformer->calculateDFT($values, $times, $maxHarmonics, $totalDuration, $numPoints);

        // Проверяем, что коэффициенты рассчитаны
        $this->assertIsArray($coefficients);
        $this->assertArrayHasKey(0, $coefficients); // Постоянная компонента

        // Проверяем, что амплитуды разумны
        foreach ($coefficients as $k => $coeff) {
            $this->assertGreaterThanOrEqual(0, $coeff['amplitude']);
            $this->assertIsFloat($coeff['phase']);
        }

        // Проверяем, что harmonics отфильтрованы по вкладу
        $this->assertLessThanOrEqual($maxHarmonics, count($coefficients) - 1); // Без постоянной
    }

    public function testCalculateHarmonicContributionsWithHoles(): void
    {
        // Тест расчета вклада гармоник с дырами в данных
        $coefficients = [
            0 => ['amplitude' => 100.0, 'phase' => 0.0],
            1 => ['amplitude' => 10.0, 'phase' => 0.0],
            2 => ['amplitude' => 5.0, 'phase' => M_PI / 4],
        ];
        $times = [0, 60, 180, 240, 360]; // Нерегулярные времена
        $totalDuration = 360;
        $numPoints = 5;

        $contributions = $this->transformer->calculateHarmonicContributions($coefficients, $times, $totalDuration, $numPoints);

        $this->assertIsArray($contributions);
        $this->assertArrayHasKey(0, $contributions);
        $this->assertArrayHasKey(1, $contributions);
        $this->assertArrayHasKey(2, $contributions);

        // Вклад постоянной компоненты должен быть большим
        $this->assertGreaterThan($contributions[1], $contributions[0]);
        $this->assertGreaterThan($contributions[2], $contributions[1]);

        // Все вклады положительные
        foreach ($contributions as $contrib) {
            $this->assertGreaterThan(0, $contrib);
        }
    }
}