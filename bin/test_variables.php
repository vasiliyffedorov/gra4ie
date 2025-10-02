<?php
declare(strict_types=1);

chdir(__DIR__ . '/../');

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
$logger = $container->get(\App\Interfaces\LoggerInterface::class);

// Симуляция изменения config_hash, но query тот же
$query = 'up{instance="localhost:9090"}';
$labelsJson = '{"__name__":"up","instance":"localhost:9090"}';

// Config 1
$currentConfig1 = $config;
$currentConfig1['corrdor_params']['default_percentiles'] = [95, 5];

// Config 2 (измененный config_hash)
$currentConfig2 = $config;
$currentConfig2['corrdor_params']['default_percentiles'] = [90, 10];

$hash1 = md5(serialize($currentConfig1));
$hash2 = md5(serialize($currentConfig2));

echo "Config1 hash: $hash1\n";
echo "Config2 hash: $hash2\n";
echo "Hashes different: " . ($hash1 !== $hash2 ? 'yes' : 'no') . "\n";

// Теперь симулировать StatsCacheManager
$statsManager = $container->get(\App\Processors\StatsCacheManager::class);

$liveData = [];
$historyData = []; // Пустой, чтобы trigger fetch

try {
    echo "Calling recalculateStats with config1...\n";
    $result1 = $statsManager->recalculateStats($query, $labelsJson, $liveData, $historyData, $currentConfig1);
    echo "Result1 config_hash: " . $result1['meta']['config_hash'] . "\n";

    echo "Calling recalculateStats with config2...\n";
    $result2 = $statsManager->recalculateStats($query, $labelsJson, $liveData, $historyData, $currentConfig2);
    echo "Result2 config_hash: " . $result2['meta']['config_hash'] . "\n";

    echo "Config hashes different: " . ($result1['meta']['config_hash'] !== $result2['meta']['config_hash'] ? 'yes' : 'no') . "\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>