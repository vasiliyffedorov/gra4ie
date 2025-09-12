
<?php
declare(strict_types=1);

namespace App\Tests;

use App\Processors\DFTProcessor;
use App\Utilities\Logger;
use PHPUnit\Framework\TestCase;

class DFTProcessorTest extends TestCase
{
    private array $config;
    private Logger $logger;
    private DFTProcessor $processor;

    protected function setUp(): void
    {
        $this->config = [
            'corrdor_params' => [
                'step' => 60,
                'max_harmonics' => 10,
                'use_common_trend' => false
            ]
        ];
        $this->logger = new Logger('php://memory', Logger::LEVEL_INFO);
        $this->processor = new DFTProcessor($this->config, $this->logger);
    }

    public function testCalculateLinearTrend(): void
    {
        $times = [1, 2, 3];
        $values = [2, 4, 6]; // Линейный тренд slope=2, intercept=0

        $result = $this->processor->calculateLinearTrend($values, $times);

        $this->assertEquals(2.0, $result['slope']);
        $this->assertEquals(0.0, $result['intercept']);
    }

    public function testCalculateLinearTrendInsufficientData(): void
    {
        $times = [1];
        $values = [5];

        $result = $this->processor->calculateLinearTrend($values, $times);

        $this->assertEquals(0, $result['slope']);
        $this->assertEquals(5, $result['intercept']);
    }

    protected function tearDown(): void
    {
        unset($this->processor, $this->logger, $this->config);
    }
}