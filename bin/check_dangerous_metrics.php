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
$requiredKeys = ['grafana_url', 'grafana_api_token', 'log_file'];
foreach ($requiredKeys as $key) {
    if (!isset($config[$key])) {
        throw new Exception("Missing required config key: $key");
    }
}

$container = new \App\DI\Container($config);
$proxy = $container->get(\App\Interfaces\GrafanaClientInterface::class);
$builder = new \App\Processors\CorridorBuilder($container);

echo "Обновление кэша метрик Grafana...\n";
$proxy->updateMetricsCache();
echo "Кэш метрик обновлён.\n";

$folderUid = 'dexnckrmtcpa8f';
$dangerThreshold = 0.5;

// Параметры для проверки: последние 6 часов, step 60s
$periodHours = 6;
$start = time() - $periodHours * 3600;
$end = time();
$step = 60;

$metricNames = $proxy->getMetricNames();
echo "Проверка {$periodHours} часов для " . count($metricNames) . " метрик...\n";

foreach ($metricNames as $metricName) {
    echo "Проверка метрики: $metricName\n";
    
    $formatted = $builder->build($metricName, $start, $end, $step, ['anomaly_concern']);
    
    $query = $proxy->getQueryForMetric($metricName);
    if ($query === false) {
        echo "Нет PromQL для создания dashboard для $metricName\n";
    }
    
    if ($formatted['status'] !== 'success') {
        echo "Ошибка при построении для $metricName: " . ($formatted['error'] ?? 'unknown') . "\n";
        continue;
    }
    
    if ($formatted['status'] !== 'success') {
        echo "Ошибка при построении для $metricName: " . ($formatted['error'] ?? 'unknown') . "\n";
        continue;
    }
    
    $results = $formatted['data']['result'] ?? [];
    $isDangerous = false;
    $maxConcernAbove = 0.0;
    
    foreach ($results as $result) {
        $metricNameInResult = $result['metric']['__name__'] ?? '';
        if ($metricNameInResult === 'anomaly_concern_above') {
            $values = $result['values'][0] ?? [];
            $concern = (float)($values[1] ?? 0) / 100;
            if ($concern > $maxConcernAbove) {
                $maxConcernAbove = $concern;
            }
            if ($concern > $dangerThreshold) {
                $isDangerous = true;
            }
        }
    }
    
    if ($isDangerous) {
        echo "Опасность обнаружена для $metricName (max_concern_above: $maxConcernAbove > $dangerThreshold)\n";
        if ($query === false) {
            echo "Не создан дашборд: нет PromQL expr\n";
        } else {
            $dashboardUrl = $proxy->createDangerDashboard($metricName, $folderUid);
            if ($dashboardUrl) {
                echo "Создан дашборд: $dashboardUrl\n";
            } else {
                echo "Ошибка создания дашборда для $metricName\n";
            }
        }
    } else {
        echo "Метрика $metricName в норме (max_concern_above: $maxConcernAbove)\n";
    }
}

echo "Проверка завершена.\n";
?>