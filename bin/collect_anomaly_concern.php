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

// Парсинг параметров командной строки
$options = getopt('', ['step:', 'min-range:', 'max-range:']);
$step = isset($options['step']) ? parseTime($options['step']) : 300; // 5m default
$minRange = isset($options['min-range']) ? parseTime($options['min-range']) : 3600; // 1h default
$maxRange = isset($options['max-range']) ? parseTime($options['max-range']) : 604800; // 7d default

function parseTime(string $timeStr): int {
    $units = [
        's' => 1,
        'm' => 60,
        'h' => 3600,
        'd' => 86400,
    ];
    if (is_numeric($timeStr)) {
        return (int)$timeStr;
    }
    $unit = substr($timeStr, -1);
    $value = (int)substr($timeStr, 0, -1);
    return $value * ($units[$unit] ?? 1);
}

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

// Собрать все metric_key и metric_data
$allMetrics = [];
$metricDataMap = [];
foreach ($instances as $instance) {
    $instanceId = $instance['id'];
    echo "Загружаем метрики для instance $instanceId\n";
    $metrics = $cacheManager->loadGrafanaIndividualMetrics($instanceId);
    echo "Метрик в instance $instanceId: " . count($metrics) . "\n";
    foreach ($metrics as $metric) {
        $allMetrics[] = $metric['metric_key'];
        $metricDataMap[$metric['metric_key']] = $metric['metric_data'];
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
    $panelUrl = $metricDataMap[$metricKey]['panel_url'] ?? '';
    if (empty($panelUrl)) {
        // Попытаться построить URL из original_query
        $metricData = $metricDataMap[$metricKey] ?? [];
        $originalQuery = $metricData['original_query'] ?? '';
        if (!empty($originalQuery)) {
            $parts = explode(':', $originalQuery, 2);
            if (count($parts) === 2) {
                $jsonStr = $parts[1];
                $labels = json_decode($jsonStr, true);
                if ($labels && isset($labels['node_name'])) {
                    $nodeName = $labels['node_name'];
                    $panelUrl = "https://81.163.20.183/graph/d/vmagent/VictoriaMetrics%20Agents%20Overview?node_name=$nodeName";
                }
            }
        }
    }
    echo "Обрабатываем метрику: $metricKey\n";
    echo "URL: " . (!empty($panelUrl) ? $panelUrl : "не найден") . "\n";

    // Рассчитать диапазон на основе max duration аномалий из кэша
    $cached = $cacheManager->loadFromCache($metricKey, '{}');
    $maxDuration = 0;
    if ($cached && isset($cached['meta']['anomaly_stats'])) {
        $durations = array_merge(
            $cached['meta']['anomaly_stats']['above']['durations'] ?? [],
            $cached['meta']['anomaly_stats']['below']['durations'] ?? []
        );
        if (!empty($durations)) {
            // Если durations - перцентили, взять последний (100-й)
            $maxDuration = is_array($durations) ? max($durations) : $durations[count($durations) - 1] ?? 0;
        }
    }
    $calculatedRange = $maxDuration > 0 ? $maxDuration * 2 : $minRange;
    $range = max($minRange, min($maxRange, $calculatedRange));
    echo "Max duration: {$maxDuration}s, calculated range: {$calculatedRange}s, final range: {$range}s\n";

    // Сделать запрос на приложение
    $query = $metricKey . '#dashboard.show_metrics=anomaly_concern';
    $start = time() - $range;
    $end = time();

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
                // Извлечь лейблы, исключая __name__
                $labels = $result['metric'];
                unset($labels['__name__']);
                echo "Добавляем:\n";
                echo "  Значение: $value\n";
                echo "  Лейблы:\n";
                echo json_encode($labels, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                echo "\n";
                $anomalyConcerns[] = [
                    'metric' => $metricKey,
                    'name' => $name,
                    'value' => $value,
                    'labels' => $labels
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
    echo "Метрика: {$item['name']}\n";
    echo "Значение: {$item['value']}\n";
    echo "Лейблы:\n";
    echo json_encode($item['labels'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    echo "\n";
}
?>