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
$cacheManager = $container->get(\App\Interfaces\CacheManagerInterface::class);

// Шаг 1: Установить начальный blacklist
$url = "http://127.0.0.1:8080"; // Пример URL
$initialBlacklist = ['datasource1', 'datasource2'];

$instance = [
    'name' => 'test_instance',
    'url' => $url,
    'token' => 'test_token',
    'blacklist_uids' => $initialBlacklist
];

echo "Setting initial blacklist: " . json_encode($initialBlacklist) . "\n";
$saved = $cacheManager->saveGrafanaInstance($instance);
if (!$saved) {
    echo "Failed to save initial instance\n";
    exit(1);
}

// Шаг 2: Симулировать extractGrafanaInstanceData без HTTP_X_DATASOURCE_UID
// Установить $_SERVER переменные
$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('8080:test_token');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
// НЕ устанавливаем HTTP_X_DATASOURCE_UID

// Вызвать функцию напрямую
function extractGrafanaInstanceData($cacheManager, $logger) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (empty($authHeader) || !preg_match('/^Basic\s+(.+)$/', $authHeader, $matches)) {
        $logger->info('Authorization header missing or not Basic');
        return null;
    }

    $credentials = base64_decode($matches[1]);
    if (!$credentials || !str_contains($credentials, ':')) {
        $logger->error('Invalid Basic auth credentials');
        return null;
    }

    list($login, $token) = explode(':', $credentials, 2);
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $url = "http://{$remoteAddr}:{$login}";

    $datasourceUid = $_SERVER['HTTP_X_DATASOURCE_UID'] ?? '';

    // Загружаем существующий экземпляр
    $existingInstance = $cacheManager->getGrafanaInstanceByUrl($url);
    $blacklistUids = $existingInstance ? $existingInstance['blacklist_uids'] : [];

    // Обновляем blacklist только при наличии UID
    if (!empty($datasourceUid) && !in_array($datasourceUid, $blacklistUids)) {
        $blacklistUids[] = $datasourceUid;
    }

    $logger->info("Extracted datasource UID: '$datasourceUid', current blacklist_uids: " . json_encode($blacklistUids));

    $instance = [
        'name' => $login,
        'url' => $url,
        'token' => $token,
        'blacklist_uids' => $blacklistUids
    ];

    $logger->info("Saving Grafana instance with blacklist_uids: " . json_encode($blacklistUids));
    $saved = $cacheManager->saveGrafanaInstance($instance);
    if ($saved) {
        $logger->info("Grafana instance saved successfully, blacklist preserved: " . json_encode($blacklistUids));
    } else {
        $logger->error("Failed to save Grafana instance, blacklist may not be preserved");
    }

    if ($saved) {
        if ($existingInstance) {
            $logger->info("Grafana instance updated: {$url}");
        } else {
            $logger->info("New Grafana instance saved: {$url}");
        }
    } else {
        $logger->error("Failed to save/update Grafana instance: {$url}");
        return null;
    }

    $id = $cacheManager->getGrafanaInstanceIdByUrl($url);
    if ($id === null) {
        $logger->error("Failed to get ID for Grafana instance: {$url}");
        return null;
    }

    $instance['id'] = $id;
    return $instance;
}

echo "Simulating request without HTTP_X_DATASOURCE_UID...\n";
$result = extractGrafanaInstanceData($cacheManager, $logger);

if ($result === null) {
    echo "Failed to extract instance data\n";
    exit(1);
}

// Шаг 3: Проверить, что blacklist не изменился
$finalInstance = $cacheManager->getGrafanaInstanceByUrl($url);
$finalBlacklist = $finalInstance['blacklist_uids'];

echo "Final blacklist: " . json_encode($finalBlacklist) . "\n";

if ($finalBlacklist === $initialBlacklist) {
    echo "SUCCESS: Blacklist preserved without changes\n";
} else {
    echo "FAILURE: Blacklist changed unexpectedly\n";
    echo "Expected: " . json_encode($initialBlacklist) . "\n";
    echo "Actual: " . json_encode($finalBlacklist) . "\n";
}
?>