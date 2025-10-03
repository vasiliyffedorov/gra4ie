<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';

header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(300);

function normalizeQuery(string $q): string {
    return trim(preg_replace('/\s+/', ' ', str_replace(["\r","\n"], '', $q)));
}

function parseValue(string $v): mixed {
    // булевы
    if (strcasecmp($v, 'true') === 0) return true;
    if (strcasecmp($v, 'false') === 0) return false;
    // массив по запятой
    if (str_contains($v, ',')) {
        return array_map('trim', explode(',', $v));
    }
    // число
    if (is_numeric($v)) {
        return str_contains($v, '.')
            ? (float)$v
            : (int)$v;
    }
    // строка
    return $v;
}

function setNested(array &$cfg, array $keys, $value): void {
    $ref = &$cfg;
    foreach ($keys as $i => $key) {
        if ($i === count($keys) - 1) {
            $ref[$key] = $value;
        } else {
            if (!isset($ref[$key]) || !is_array($ref[$key])) {
                $ref[$key] = [];
            }
            $ref = &$ref[$key];
        }
    }
}

function sendJson(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function jsonSuccess($data, int $code = 200): void {
    sendJson(['status' => 'success', 'data' => $data], $code);
}

function jsonError(string $msg, int $code = 400): void {
    sendJson(['status' => 'error', 'error' => $msg], $code);
}

// 1) читаем конфиг
$flatIni = parse_ini_file(__DIR__.'/config/config.cfg', true, INI_SCANNER_RAW);
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

$config = nest($flatIni);

// ENV override (для совместимости, но параметры устарели)
$envUrl = getenv('GRAFANA_URL');
if ($envUrl !== false && $envUrl !== '') {
    $config['grafana_url'] = $envUrl;
}
$envToken = getenv('GRAFANA_API_TOKEN');
if ($envToken !== false && $envToken !== '') {
    $config['grafana_api_token'] = $envToken;
}

// Валидация конфига
$requiredKeys = ['log_file'];
foreach ($requiredKeys as $key) {
    if (!isset($config[$key])) {
        jsonError("Missing required config key: $key", 500);
    }
}

$container = new \App\DI\Container($config);
$logger = $container->get(\App\Interfaces\LoggerInterface::class);
$cacheManager = $container->get(\App\Interfaces\CacheManagerInterface::class);

function extractGrafanaInstanceData(\App\Interfaces\CacheManagerInterface $cacheManager, \App\Interfaces\LoggerInterface $logger): ?array {
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

$directories = [
    __DIR__ . '/logs',
    __DIR__ . '/cache'
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            $logger->error("Не удалось создать директорию: $dir");
            $logger->error('Internal server error');
            jsonError('Internal server error', 500);
        }
    }
}

// Извлечение данных Grafana instance
$currentInstance = extractGrafanaInstanceData($cacheManager, $logger);
if ($currentInstance === null) {
    jsonError('Unauthorized', 401);
}

// Создание GrafanaProxyClient с instance
$proxy = new \App\Clients\GrafanaProxyClient($currentInstance, $logger, $cacheManager);

// Регистрация proxy в container для CorridorBuilder
$container->set(\App\Interfaces\GrafanaClientInterface::class, $proxy);

// Автоматическое обновление кэша дашбордов, если пустой
if (empty($proxy->getMetricNames())) {
    $logger->info("Кэш дашбордов пустой, автоматически обновляем...");
    shell_exec('php ' . __DIR__ . '/bin/update_dashboards_cache.php ' . $currentInstance['id']);
    $proxy->reloadMetricsCache();
    $logger->info("Кэш дашбордов обновлён автоматически.");
}

// 3) роутинг
$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// GET /api/v1/labels
if ($method==='GET' && $path==='/api/v1/labels') {
    jsonSuccess($proxy->getMetricNames());
    exit;
}

// POST /api/v1/labels
if ($method==='POST' && $path==='/api/v1/labels') {
    jsonSuccess($proxy->getMetricNames());
    exit;
}

// GET /api/v1/metadata
if ($method==='GET' && $path==='/api/v1/metadata') {
    $metrics = $proxy->getMetricNames();
    $metadata = [];
    foreach ($metrics as $metric) {
        $metadata[$metric] = [
            [
                'type' => 'gauge',
                'help' => "Metric from Grafana dashboard panel: $metric",
                'unit' => ''
            ]
        ];
    }
    jsonSuccess($metadata);
    exit;
}

// GET /api/v1/label/__name__/values
if ($method==='GET' && $path==='/api/v1/label/__name__/values') {
    jsonSuccess($proxy->getMetricNames());
    exit;
}

// GET /api/v1/status/buildinfo
if ($method==='GET' && $path==='/api/v1/status/buildinfo') {
    jsonSuccess([
        'status' => 'success',
        'data' => [
            'version' => '2.0.0',
            'revision' => 'unknown',
            'branch' => 'unknown',
            'buildUser' => 'unknown',
            'buildDate' => 'unknown',
            'goVersion' => 'unknown'
        ]
    ]);
    exit;
}

// POST /api/v1/query
if ($method==='POST' && $path==='/api/v1/query') {
    jsonSuccess(['resultType' => 'vector', 'result' => []]);
    exit;
}

// POST /api/v1/query_range
if ($method==='POST' && $path==='/api/v1/query_range') {
    $params = [];
    parse_str(file_get_contents('php://input'), $params);
    if (empty($params['query'])) {
        jsonError('Missing query', 400);
    }

    // разбираем override-параметры
    $normalizedQuery = normalizeQuery($params['query']);
    $overrides = [];
    if (str_contains($normalizedQuery, '#')) {
        list($cleanQuery, $overrideString) = explode('#', $normalizedQuery, 2);
        $params['query'] = trim($cleanQuery);
        foreach (explode(';', $overrideString) as $overrideChunk) {
            if (!str_contains($overrideChunk, '=')) continue;
            list($k, $v) = array_map('trim', explode('=', $overrideChunk, 2));
            $overrides[$k] = $v;
        }
    }

    // накладываем их на копию конфига
    $finalConfig = $config;
    foreach ($overrides as $overrideKey => $overrideValue) {
        $keys = explode('.', $overrideKey);
        setNested($finalConfig, $keys, parseValue($overrideValue));
    }

    // параметры запроса
    $start = (int)($params['start'] ?? time()-3600);
    $end   = (int)($params['end']   ?? time());
    $step  = (int)($params['step']  ?? 60);

    // и строим коридор
    $corridorBuilder = new \App\Processors\CorridorBuilder($container);
    $corridorBuilder->updateConfig($finalConfig);
    $result = $corridorBuilder->build(
        $params['query'],
        $start,
        $end,
        $step
    );

    $logger->info("Query range result built for query: " . $params['query']);
    echo json_encode($result);
    exit;
}

// POST /api/ds/query
if ($method==='POST' && $path==='/api/ds/query') {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);
    if (!$data) {
        jsonError('Invalid JSON', 400);
    }

    // Проксировать к Grafana
    $url = $currentInstance['url'] . '/api/ds/query';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $currentInstance['token'],
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    http_response_code($code);
    echo $response;
    exit;
}

// всё остальное 404
jsonError('Not found', 404);
?>