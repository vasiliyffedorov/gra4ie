<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\DI\Container;

// Загружаем конфиг (упрощенная версия для теста)
$config = [
    'cache' => [
        'database' => [
            'path' => __DIR__ . '/../cache/gra4ie_cache.db',
            'max_ttl' => 86400,
        ],
    ],
    'log_level' => 'INFO',
    'log_file' => __DIR__ . '/../logs/app.log',
];

$container = new Container($config);
$cache = $container->get(\Psr\SimpleCache\SimpleCacheInterface::class);
$logger = $container->get(\App\Interfaces\LoggerInterface::class);

// Тестовые данные
$testQuery = 'rate(http_requests_total{job="api-server"}[5m])';
$testLabelsJson = json_encode(['instance' => '10.0.0.1:8080', 'job' => 'api-server', 'mode' => 'serving']);
$testKey = $testQuery . '|' . $testLabelsJson;

$testValue = [
    'meta' => [
        'dataStart' => 1625097600,
        'step' => 60,
        'totalDuration' => 3600,
        'dft_rebuild_count' => 0,
        'labels' => json_decode($testLabelsJson, true),
    ],
    'dft_upper' => [
        'coefficients' => [1.0, 0.5, 0.0],
        'trend' => ['slope' => 0.01, 'intercept' => 100],
    ],
    'dft_lower' => [
        'coefficients' => [0.8, 0.4, 0.0],
        'trend' => ['slope' => 0.005, 'intercept' => 90],
    ],
];

// Установка
if ($cache->set($testKey, $testValue)) {
    $logger->info("Значение успешно сохранено в PSR кэш");
} else {
    $logger->error("Ошибка сохранения в PSR кэш");
}

// Получение
$retrieved = $cache->get($testKey);
if ($retrieved !== null) {
    $logger->info("Значение успешно получено из PSR кэш");
    $logger->info("DFT rebuild count: " . ($retrieved['meta']['dft_rebuild_count'] ?? 'N/A'));
} else {
    $logger->info("Значение не найдено в PSR кэш");
}

// Проверка существования
if ($cache->has($testKey)) {
    $logger->info("Кэш существует для ключа: $testKey");
}

// Удаление
if ($cache->delete($testKey)) {
    $logger->info("Значение успешно удалено из PSR кэш");
} else {
    $logger->error("Ошибка удаления из PSR кэш");
}

// Очистка
if ($cache->clear()) {
    $logger->info("Кэш успешно очищен");
} else {
    $logger->error("Ошибка очистки кэша");
}
?>