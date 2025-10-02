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

// Получить метрику из аргументов командной строки
$metricName = $argv[1] ?? null;
if (!$metricName) {
    $logger->error("Не указана метрика. Использование: php clear_cache_for_metric.php <metric_name>");
    exit(1);
}

// Предполагаем query = metricName, labelsJson = '{}'
$query = $metricName;
$labelsJson = '{}';

// Генерируем metric_hash как в SQLiteCacheIO
$labels = json_decode($labelsJson, true);
if (is_array($labels)) {
    // deepKsort
    function deepKsort(array &$arr): void {
        foreach ($arr as &$v) {
            if (is_array($v)) {
                deepKsort($v);
            }
        }
        ksort($arr);
    }
    deepKsort($labels);
    $normalizedLabelsJson = json_encode($labels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} else {
    $normalizedLabelsJson = $labelsJson;
}
$metricHash = md5($query . $normalizedLabelsJson);

$dbPath = $config['cache']['database']['path'];
$db = new PDO("sqlite:$dbPath");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$logger->info("Очистка кеша для метрики $metricName (query=$query, labelsJson=$labelsJson, metricHash=$metricHash)");

// Получаем query_id
$stmt = $db->prepare("SELECT id FROM queries WHERE query = :query");
$stmt->execute([':query' => $query]);
$queryId = $stmt->fetchColumn();

if (!$queryId) {
    $logger->info("Query $query не найден в кеше, ничего удалять");
    echo "Кеш для метрики $metricName уже пуст.\n";
    exit(0);
}

// Удаляем из dft_cache
$stmt = $db->prepare("DELETE FROM dft_cache WHERE query_id = :query_id AND metric_hash = :metric_hash");
$stmt->execute([':query_id' => $queryId, ':metric_hash' => $metricHash]);
$deletedDft = $stmt->rowCount();

// Удаляем из metrics_cache_permanent
$stmt = $db->prepare("DELETE FROM metrics_cache_permanent WHERE query_id = :query_id AND metric_hash = :metric_hash");
$stmt->execute([':query_id' => $queryId, ':metric_hash' => $metricHash]);
$deletedPermanent = $stmt->rowCount();

$logger->info("Удалено записей: dft_cache=$deletedDft, metrics_cache_permanent=$deletedPermanent");

echo "Кеш для метрики $metricName очищен.\n";
?>