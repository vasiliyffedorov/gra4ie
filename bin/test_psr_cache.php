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
    'grafana_url' => 'http://localhost:3000',
    'grafana_api_token' => 'test_token',
    'blacklist_datasource_ids' => [],
];

$container = new Container($config);
$cache = $container->get(\Psr\SimpleCache\SimpleCacheInterface::class);

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
    echo "Значение успешно сохранено в PSR кэш.\n";
} else {
    echo "Ошибка сохранения в PSR кэш.\n";
}

// Получение
$retrieved = $cache->get($testKey);
if ($retrieved !== null) {
    echo "Значение успешно получено из PSR кэш.\n";
    echo "DFT rebuild count: " . ($retrieved['meta']['dft_rebuild_count'] ?? 'N/A') . "\n";
} else {
    echo "Значение не найдено в PSR кэш.\n";
}

// Проверка существования
if ($cache->has($testKey)) {
    echo "Кэш существует для ключа: $testKey\n";
}

// Удаление
if ($cache->delete($testKey)) {
    echo "Значение успешно удалено из PSR кэш.\n";
} else {
    echo "Ошибка удаления из PSR кэш.\n";
}

// Очистка
if ($cache->clear()) {
    echo "Кэш успешно очищен.\n";
} else {
    echo "Ошибка очистки кэша.\n";
}
?>