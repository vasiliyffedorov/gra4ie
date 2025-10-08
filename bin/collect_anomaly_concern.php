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

// Получить все инстансы
$instances = $cacheManager->loadGrafanaInstances();
echo "Найдено инстансов: " . count($instances) . "\n";
if (empty($instances)) {
    $logger->error("Нет доступных инстансов Grafana");
    exit(1);
}

// Используем первый инстанс для auth
$instance = $instances[0];
$authHeader = 'Authorization: Basic ' . base64_encode($instance['url'] . ':' . $instance['token']);
echo "Используем auth для instance: {$instance['url']}\n";

// Собрать все metric_key
$allMetrics = [];
foreach ($instances as $instance) {
    $instanceId = $instance['id'];
    echo "Загружаем метрики для instance $instanceId\n";
    $metrics = $cacheManager->loadGrafanaIndividualMetrics($instanceId);
    echo "Метрик в instance $instanceId: " . count($metrics) . "\n";
    foreach ($metrics as $metric) {
        $allMetrics[] = $metric['metric_key'];
    }
}

echo "Всего метрик: " . count($allMetrics) . "\n";
if (empty($allMetrics)) {
    $logger->info("Нет метрик для обработки");
    exit(0);
}

$logger->info("Найдено " . count($allMetrics) . " метрик");

// Собрать anomaly_concern
$anomalyConcerns = [];
$processed = 0;

foreach ($allMetrics as $metricKey) {
    echo "Обрабатываем метрику: $metricKey\n";
    // Сделать запрос на приложение
    $query = $metricKey . '#dashboard.show_metrics=anomaly_concern';
    $start = time() - 3600; // последний час
    $end = time();
    $step = 60;

    $postData = http_build_query([
        'query' => $query,
        'start' => $start,
        'end' => $end,
        'step' => $step
    ]);

    $ch = curl_init('http://localhost:9093/api/v1/query_range');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        $authHeader
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "HTTP код: $httpCode\n";
    if ($httpCode !== 200) {
        $logger->warning("Ошибка запроса для метрики $metricKey: HTTP $httpCode");
        continue;
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['data']['result'])) {
        echo "Неверный ответ: " . substr($response, 0, 200) . "\n";
        $logger->warning("Неверный ответ для метрики $metricKey");
        continue;
    }

    echo "Результатов в ответе: " . count($data['data']['result']) . "\n";
    // Извлечь anomaly_concern_*
    foreach ($data['data']['result'] as $result) {
        $name = $result['metric']['__name__'] ?? '';
        echo "Метрика: $name\n";
        if (str_starts_with($name, 'anomaly_concern_')) {
            $values = $result['values'] ?? [];
            if (!empty($values)) {
                // Взять последнее значение
                $lastValue = end($values);
                $value = (float)$lastValue[1];
                echo "Добавляем: $name = $value\n";
                $anomalyConcerns[] = [
                    'metric' => $metricKey,
                    'name' => $name,
                    'value' => $value
                ];
            }
        }
    }
    $processed++;
}

$logger->info("Собрано " . count($anomalyConcerns) . " anomaly_concern метрик");

// Отсортировать по возрастанию value
usort($anomalyConcerns, fn($a, $b) => $a['value'] <=> $b['value']);

// Вывести
foreach ($anomalyConcerns as $item) {
    echo sprintf("%s: %s = %.2f\n", $item['metric'], $item['name'], $item['value']);
}
?>