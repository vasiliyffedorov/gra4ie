<?php
/**
 * Тестовый скрипт для проверки парсинга config.cfg
 * Копирует логику из index.php: parse_ini_file + nest
 */

// Функция nest из index.php
function nest(array $flatEntries): array {
    $out = [];
    foreach ($flatEntries as $key => $value) {
        $parts = explode('.', $key);
        $ref = &$out;
        foreach ($parts as $i => $part) {
            if ($i === count($parts)-1) {
                // списки через запятую
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

// Парсим config.cfg
$flatIni = parse_ini_file(__DIR__ . '/config/config.cfg', true, INI_SCANNER_RAW);
if ($flatIni === false) {
    die("Ошибка парсинга config.cfg\n");
}

$config = nest($flatIni);

// Валидация ключевых полей (из index.php)
// Проверка required keys как в index.php (top-level без точки)
$requiredKeys = ['grafana_url', 'grafana_api_token', 'log_file'];
foreach ($requiredKeys as $key) {
    if (!isset($config[$key])) {
        die("Отсутствует ключ: $key\n");
    }
}

// Выводим структуру (сокращенную для читаемости)
echo "Конфиг парсится успешно!\n";
echo "Пример структуры:\n";
echo "- grafana_url: " . var_export($config['grafana_url'] ?? 'N/A', true) . "\n";
echo "- grafana_api_token: " . var_export(substr($config['grafana_api_token'] ?? '', 0, 10) . '...', true) . "\n"; // Частично для безопасности
echo "- log_level: " . var_export($config['log_level'] ?? 'N/A', true) . "\n";
echo "- log_file: " . var_export($config['log_file'] ?? 'N/A', true) . "\n";
echo "- corridor.type: " . var_export($config['corridor']['type'] ?? 'N/A', true) . "\n";
echo "- blacklist.datasource_ids: " . var_export($config['blacklist']['datasource_ids'] ?? 'N/A', true) . "\n";
echo "- performance.enabled (bool): " . var_export($config['performance']['enabled'] ?? 'N/A', true) . "\n";
echo "- cache.percentiles (array): " . var_export($config['cache']['percentiles'] ?? 'N/A', true) . "\n";
echo "- timeout.cache_build (int): " . var_export($config['timeout']['cache_build'] ?? 'N/A', true) . "\n";

// Полная структура для отладки (если нужно)
echo "\nПолная структура config (var_export):\n";
var_export($config);
?>