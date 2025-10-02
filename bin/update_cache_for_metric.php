<?php
declare(strict_types=1);

chdir(__DIR__ . '/../');

$directories = [
    './logs',
    './cache'
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            throw new Exception("Не удалось создать директорию: $dir");
        }
    }
}

require './vendor/autoload.php';

use App\DI\Container;
use App\Interfaces\LoggerInterface;
use App\Processors\StatsCacheManager;

// Читаем конфиг аналогично index.php
$flatIni = parse_ini_file('./config/config.cfg', true, INI_SCANNER_RAW);
if ($flatIni === false) {
    throw new Exception("Не удалось прочитать config.cfg");
}

function nest(array $flatEntries): array {
    $out = [];
    foreach ($flatEntries as $key => $value) {
        $parts = explode('.', $key);
        $ref = &$out;
        foreach ($parts as $i => $part) {
            if ($i === count($parts)-1) {
                if (str_contains($value, ',')) {
                    $ref[$part] = array_map('trim', explode(',', $value));
                } elseif (is_numeric($value)) {
                    $ref[$part] = str_contains($value, '.') ? (float)$value : (int)$value;
                } elseif (strtolower($value)==='true') {
                    $ref[$part] = true;
                } elseif (strtolower($value)==='false') {
                    $ref[$part] = false;
                } else {
                    $ref[$part] = $value;
                }
            } else {
                if (!isset($ref[$part]) || !is_array($ref[$part])) {
                    $ref[$part] = [];
                }
                $ref = &$ref[$part];
            }
        }
    }
    return $out;
}

$config = nest($flatIni);

// Валидация
$requiredKeys = ['log_file'];
foreach ($requiredKeys as $key) {
    if (!isset($config[$key])) {
        throw new Exception("Missing required config key: $key");
    }
}

$container = new Container($config);
$logger = $container->get(LoggerInterface::class);
$cacheManager = $container->get(\App\Interfaces\CacheManagerInterface::class);

// Получить instance данные (предполагаем id=1)
$instances = $cacheManager->loadGrafanaInstances();
$instance = null;
foreach ($instances as $inst) {
    if ($inst['id'] == 1) {
        $instance = $inst;
        break;
    }
}
if (!$instance) {
    $logger->error("Instance с id 1 не найден");
    exit(1);
}

// Создать proxy с instance
$proxy = new \App\Clients\GrafanaProxyClient($instance, $logger, $cacheManager);

// Регистрация proxy в container
$container->set(\App\Interfaces\GrafanaClientInterface::class, $proxy);

$statsCacheManager = $container->get(StatsCacheManager::class);

// Получить метрику из аргументов командной строки
$metricName = $argv[1] ?? null;
if (!$metricName) {
    $logger->error("Не указана метрика. Использование: php update_cache_for_metric.php <metric_name>");
    exit(1);
}

$query = $metricName;
$labelsJson = '{}';

$logger->info("Обновление кеша для метрики $metricName");

try {
    $result = $statsCacheManager->recalculateStats($query, $labelsJson, [], [], $config);
    $logger->info("Кеш для метрики $metricName обновлен успешно");
    echo "Кеш для метрики $metricName обновлен.\n";
} catch (Exception $e) {
    $logger->error("Ошибка при обновлении кеша для $metricName: " . $e->getMessage());
    echo "Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
?>