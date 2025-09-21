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

$container = new \App\DI\Container($config);
$proxy = $container->get(\App\Interfaces\GrafanaClientInterface::class);
$logger = $container->get(\App\Interfaces\LoggerInterface::class);
$builder = new \App\Processors\CorridorBuilder($container);

$logger->info("Обновление кэша метрик Grafana...");
$proxy->updateMetricsCache();
$logger->info("Кэш метрик обновлён");

$folderUid = 'dexnckrmtcpa8f';
$dangerThreshold = 0.5;

// Параметры для проверки: последние 1 час, step 300s (5 мин, для ускорения)
$periodHours = 1;
$start = time() - $periodHours * 3600;
$end = time();
$step = 300;

$metricNames = $proxy->getMetricNames();
$logger->info("Проверка {$periodHours} часов для " . count($metricNames) . " метрик");

$dangerousCount = 0;

foreach ($metricNames as $metricName) {
    $logger->info("Проверка метрики: $metricName");
    
    $formatted = $builder->build($metricName, $start, $end, $step, ['anomaly_concern']);
    
    $query = $proxy->getQueryForMetric($metricName);
    if ($query === false) {
        $logger->info("Нет PromQL для создания dashboard для $metricName");
    }
    
    if ($formatted['status'] !== 'success') {
        $logger->error("Ошибка при построении для $metricName: " . ($formatted['error'] ?? 'unknown'));
        continue;
    }
    
    if ($formatted['status'] !== 'success') {
        $logger->error("Ошибка при построении для $metricName: " . ($formatted['error'] ?? 'unknown'));
        continue;
    }
    
    $results = $formatted['data']['result'] ?? [];
    $isDangerous = false;
    $maxConcernAbove = 0.0;
    
    foreach ($results as $result) {
        $metricNameInResult = $result['metric']['__name__'] ?? '';
        if ($metricNameInResult === 'anomaly_concern_above') {
            $values = $result['values'][0] ?? [];
            $concern = (float)($values[1] ?? 0);
            if ($concern > $maxConcernAbove) {
                $maxConcernAbove = $concern;
            }
            if ($concern > $dangerThreshold) {
                $isDangerous = true;
            }
        }
    }

    $status = $isDangerous ? 'Dangerous' : 'Safe';
    echo "Metric: $metricName, concern: $maxConcernAbove, status: $status\n";
    
    if ($isDangerous) {
        $dangerousCount++;
        $logger->warning("Опасность обнаружена для $metricName (max_concern_above: $maxConcernAbove > $dangerThreshold)");
        if ($query === false) {
            $logger->warning("Не создан дашборд: нет PromQL expr");
        } else {
            $dashboardUrl = $proxy->createDangerDashboard($metricName, $folderUid);
            if ($dashboardUrl) {
                $logger->info("Создан дашборд: $dashboardUrl");
                echo "Dashboard created: $dashboardUrl\n";
            } else {
                $logger->error("Ошибка создания дашборда для $metricName");
            }
        }
    } else {
        $logger->info("Метрика $metricName в норме (max_concern_above: $maxConcernAbove)");
    }
}

$logger->info("Проверка завершена");
echo "Check completed. Dangerous metrics: $dangerousCount out of " . count($metricNames) . "\n";
?>