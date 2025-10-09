<?php
declare(strict_types=1);

chdir(__DIR__ . '/../');

require './vendor/autoload.php';

use App\DI\Container;

// Читаем конфиг
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

$container = new Container($config);
$cacheManager = $container->get(\App\Interfaces\CacheManagerInterface::class);

// Пример метрики
$query = 'Patroni Details';
$labelsJson = '{"replication_set":""}';

$cached = $cacheManager->loadFromCache($query, $labelsJson);

if ($cached === null) {
    echo "Кэш для метрики '$query' с labels '$labelsJson' не найден.\n";
    exit(1);
}

echo "Кэш найден.\n";

if (isset($cached['meta']['anomaly_stats'])) {
    $anomalyStats = $cached['meta']['anomaly_stats'];
    echo "anomaly_stats присутствуют.\n";

    $durationsAbove = $anomalyStats['above']['durations'] ?? [];
    $durationsBelow = $anomalyStats['below']['durations'] ?? [];

    echo "durations above: " . json_encode($durationsAbove) . "\n";
    echo "durations below: " . json_encode($durationsBelow) . "\n";

    $allDurations = array_merge($durationsAbove, $durationsBelow);
    if (!empty($allDurations)) {
        $maxDuration = is_array($allDurations) ? max($allDurations) : $allDurations[count($allDurations) - 1] ?? 0;
        echo "maxDuration: {$maxDuration}s\n";
    } else {
        echo "durations пустые, maxDuration: 0s\n";
    }
} else {
    echo "anomaly_stats отсутствуют в meta.\n";
}

echo "Полный meta: " . json_encode($cached['meta'], JSON_PRETTY_PRINT) . "\n";
?>