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

require_once './app/DI/Container.php';

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

$container = new Container($config);
$proxy = $container->get(GrafanaClientInterface::class);

echo "Обновление кэша метрик Grafana...\n";
$proxy->updateMetricsCache();
echo "Кэш метрик Grafana обновлён.\n";
?>