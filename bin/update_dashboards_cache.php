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
use App\Interfaces\GrafanaClientInterface;

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
$cacheManager = $container->get(\App\Interfaces\CacheManagerInterface::class);
$logger = $container->get(\App\Interfaces\LoggerInterface::class);

// Получить instance_id из аргументов командной строки
$instanceId = $argv[1] ?? null;
if (!$instanceId || !is_numeric($instanceId)) {
    $logger->error("Не указан или неверный instance_id. Использование: php update_dashboards_cache.php <instance_id>");
    exit(1);
}
$instanceId = (int)$instanceId;

// Получить instance данные
$instances = $cacheManager->loadGrafanaInstances();
$instance = null;
foreach ($instances as $inst) {
    if ($inst['id'] == $instanceId) {
        $instance = $inst;
        break;
    }
}
if (!$instance) {
    $logger->error("Instance с id $instanceId не найден");
    exit(1);
}

// Создать proxy с instance
$proxy = new \App\Clients\GrafanaProxyClient($instance, $logger, $cacheManager);

$logger->info("Обновление кэша метрик Grafana для instance $instanceId");
$proxy->updateMetricsCache();
$logger->info("Кэш метрик Grafana обновлён для instance $instanceId");
?>