<?php
// Парсинг аргументов командной строки
$options = getopt('', ['url:', 'token:', 'dashboard:']);
if (!isset($options['url']) || !isset($options['token']) || !isset($options['dashboard'])) {
    die("Usage: php grafana_variables.php --url=<url> --token=<token> --dashboard=<dashboard_uid>\n");
}
$url = $options['url'];
$token = $options['token'];
$dashboard_uid = $options['dashboard'];

// Пользовательские исключения
class GrafanaAPIException extends Exception {}
class VariableParseException extends Exception {}
class DataFetchException extends Exception {}
class CombinationException extends Exception {}

// Класс Config для хранения конфигурации
class Config {
    public $url;
    public $token;
    public $slug;

    public function __construct($url, $token, $slug) {
        $this->url = $url;
        $this->token = $token;
        $this->slug = $slug;
    }
}

// Класс GrafanaAPI для взаимодействия с Grafana API
class GrafanaAPI {
    private $config;
    private $dashboardCache = [];

    public function __construct(Config $config) {
        $this->config = $config;
    }

    private function httpRequest(string $method, string $url, ?string $body = null): ?string {
        $maxRetries = 2;
        $retryCount = 0;

        while ($retryCount <= $maxRetries) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->config->token,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $resp = curl_exec($ch);
            $err = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!$err && $code < 400) {
                return $resp;
            }

            $errorDetails = $err ?: "HTTP status $code";
            if ($code >= 400 && $resp) {
                $errorDetails .= ", Body: " . substr($resp, 0, 500);
            }

            $retryCount++;
            if ($retryCount <= $maxRetries && ($err || in_array($code, [500, 502, 503, 504]))) {
                sleep(1 * $retryCount);
            } else {
                return null;
            }
        }

        return null;
    }

    public function searchDashboards($query, $type) {
        $url = $this->config->url . '/api/search?query=' . urlencode($query) . '&type=' . urlencode($type);
        $response = $this->httpRequest('GET', $url);
        if (!$response) {
            throw new GrafanaAPIException('Failed to search dashboards');
        }
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new GrafanaAPIException('JSON decode error: ' . json_last_error_msg());
        }
        return $data;
    }

    public function getDashboard($uid) {
        if (isset($this->dashboardCache[$uid])) {
            return $this->dashboardCache[$uid];
        }
        $url = $this->config->url . '/api/dashboards/uid/' . $uid;
        $response = $this->httpRequest('GET', $url);
        if (!$response) {
            throw new GrafanaAPIException('Failed to get dashboard');
        }
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new GrafanaAPIException('JSON decode error: ' . json_last_error_msg());
        }
        $this->dashboardCache[$uid] = $data;
        return $data;
    }

    public function getDatasources() {
        $url = $this->config->url . '/api/datasources';
        $response = $this->httpRequest('GET', $url);
        if (!$response) {
            throw new GrafanaAPIException('Failed to get datasources');
        }
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new GrafanaAPIException('JSON decode error: ' . json_last_error_msg());
        }
        return $data;
    }
}

// Класс VariableParser для парсинга переменных из dashboard
class VariableParser {
    public function parseVariables($dashboard) {
        if (!isset($dashboard['templating']['list'])) {
            throw new VariableParseException('No variables found in dashboard');
        }
        $variables = [];
        foreach ($dashboard['templating']['list'] as $var) {
            $parsed = [
                'name' => $var['name'],
                'type' => $var['type'],
                'query' => isset($var['query']) ? $var['query'] : null,
                'datasource' => isset($var['datasource']) ? $var['datasource'] : null,
                'options' => isset($var['options']) ? $var['options'] : []
            ];
            if ($parsed['type'] === 'custom' && empty($parsed['options']) && isset($parsed['query'])) {
                $values = array_map('trim', explode(',', $parsed['query']));
                $parsed['options'] = array_map(function($v) { return ['value' => $v, 'text' => $v]; }, $values);
            }
            if (($parsed['type'] === 'constant' || $parsed['type'] === 'textbox') && empty($parsed['options']) && isset($parsed['query'])) {
                $values = array_map('trim', explode(',', $parsed['query']));
                $parsed['options'] = array_map(function($v) { return ['value' => $v, 'text' => $v]; }, $values);
            }
            if ($parsed['type'] === 'query' && isset($parsed['datasource'])) {
                if (is_array($parsed['datasource'])) {
                    $parsed['datasource_type'] = $parsed['datasource']['type'] ?? null;
                    $parsed['datasource_uid'] = $parsed['datasource']['uid'] ?? null;
                } else {
                    $parsed['datasource_uid'] = $parsed['datasource'];
                    $parsed['datasource_type'] = null;
                }
                // Определение datasource_type по refId, если не установлен
                if ($parsed['datasource_type'] === null) {
                    $refId = $parsed['query']['refId'] ?? '';
                    if (stripos($refId, 'Prometheus') !== false) {
                        $parsed['datasource_type'] = 'prometheus';
                    } elseif (stripos($refId, 'Influx') !== false) {
                        $parsed['datasource_type'] = 'influxdb';
                    } elseif (stripos($refId, 'ClickHouse') !== false) {
                        $parsed['datasource_type'] = 'grafana-clickhouse-datasource';
                    }
                }
            }
            $variables[] = $parsed;
        }
        return $variables;
    }
}

// Класс DataFetcher для fetch опций для query-типов переменных
class DataFetcher {
    private $api;
    private $config;
    private $dsMap;
    private $dsNameMap;
    private $defaultDsUid;

    public function __construct(GrafanaAPI $api, Config $config, $dsMap, $datasources, $defaultDsUid = null) {
        $this->api = $api;
        $this->config = $config;
        $this->dsMap = $dsMap;
        $this->dsNameMap = [];
        foreach ($datasources as $ds) {
            $this->dsNameMap[$ds['name']] = $ds['uid'];
        }
        $this->defaultDsUid = $defaultDsUid;
    }

    public function fetchOptions($variable, $datasource_type = null, $interpolatedQuery = null) {
        if ($variable['type'] !== 'query') {
            return $variable['options'];
        }
        $url = $this->config->url . '/api/ds/query';
        $query = $interpolatedQuery ?? ($variable['query']['query'] ?? $variable['query']);
        $datasourceUid = is_array($variable['datasource']) ? $variable['datasource']['uid'] : $variable['datasource'];
        if (!isset($this->dsMap[$datasourceUid])) {
            if (isset($this->dsNameMap[$datasourceUid])) {
                $datasourceUid = $this->dsNameMap[$datasourceUid];
            } elseif ($this->defaultDsUid) {
                $datasourceUid = $this->defaultDsUid;
            }
        }
        $datasourceId = $this->dsMap[$datasourceUid];
        if ($datasource_type === 'influxdb') {
            $queryObj = [
                "refId" => "metricFindQuery",
                "query" => $query,
                "rawQuery" => true,
                "adhocFilters" => [],
                "rawSql" => "",
                "alias" => "",
                "limit" => "",
                "measurement" => "",
                "policy" => "",
                "slimit" => "",
                "tz" => "",
                "datasource" => ["type" => "influxdb", "uid" => $datasourceUid],
                "datasourceId" => $datasourceId,
                "maxDataPoints" => 1000
            ];
            $payload = [
                "queries" => [$queryObj],
                "from" => (string)((time() - 3600) * 1000),
                "to" => (string)((time() + 3600) * 1000)
            ];
        } elseif ($datasource_type === 'prometheus') {
            $expr = $query;
            $refId = "metricFindQuery";
            $rawQuery = false;
            if (preg_match('/^label_values\(([^,]+),\s*([^)]+)\)$/', $query, $matches)) {
                $metric = trim($matches[1]);
                $label = trim($matches[2]);
                $expr = "count by ($label) ($metric)";
                $rawQuery = true;
            }
            $queryObj = [
                "refId" => $refId,
                "expr" => $expr,
                "rawQuery" => $rawQuery,
                "datasource" => ["type" => "prometheus", "uid" => $datasourceUid],
                "datasourceId" => $datasourceId
            ];
            $payload = [
                "queries" => [$queryObj],
                "from" => (string)((time() - 3600) * 1000),
                "to" => (string)((time() + 3600) * 1000)
            ];
        } elseif ($datasource_type === 'grafana-clickhouse-datasource') {
            $queryObj = [
                "rawSql" => $query,
                "queryType" => "sql",
                "refId" => "A",
                "meta" => ["timezone" => "Europe/Moscow"],
                "datasource" => ["type" => "grafana-clickhouse-datasource", "uid" => $datasourceUid],
                "datasourceId" => $datasourceId
            ];
            $from = (time() - 3600) * 1000;
            $to = (time() + 3600) * 1000;
            $payload = [
                "queries" => [$queryObj],
                "from" => (string)$from,
                "to" => (string)$to
            ];
        } else {
            // Default to old behavior if needed, but according to task, only influxdb and prometheus
            $queryObj = [
                "refId" => "A",
                "datasource" => $variable['datasource'],
                "query" => $query
            ];
            $from = (time() - 3600) * 1000;
            $to = (time() + 3600) * 1000;
            $payload = [
                "queries" => [$queryObj],
                "from" => (string)$from,
                "to" => (string)$to
            ];
        }
        $body = json_encode($payload);
        echo "DEBUG: Sending to ds/query for variable {$variable['name']}: " . $body . "\n";
        $response = $this->httpRequest('POST', $url, $body);
        echo "DEBUG: Response for variable {$variable['name']}: " . $response . "\n";
        if (!$response) {
            throw new DataFetchException('Failed to fetch options for variable ' . $variable['name']);
        }
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new DataFetchException('JSON decode error: ' . json_last_error_msg());
        }
        // Extract values from /api/ds/query response
        $values = [];
        // Find any refId in results
        $refId = null;
        if (isset($data['results']) && is_array($data['results'])) {
            $refId = array_key_first($data['results']);
        }
        if ($refId === null) {
            return $values; // No results
        }
        if ($datasource_type === 'prometheus') {
            // For Prometheus, extract unique label values from frames
            if (isset($data['results'][$refId]['frames'])) {
                $unique = [];
                foreach ($data['results'][$refId]['frames'] as $frame) {
                    if (isset($frame['schema']['fields'])) {
                        foreach ($frame['schema']['fields'] as $field) {
                            if (isset($field['labels']) && is_array($field['labels'])) {
                                foreach ($field['labels'] as $labelValue) {
                                    $unique[$labelValue] = true;
                                }
                            }
                        }
                    }
                }
                foreach (array_keys($unique) as $val) {
                    $values[] = ['value' => $val, 'text' => $val];
                }
            }
        } else {
            if (isset($data['results'][$refId]['frames'][0]['data']['values'])) {
                $rawValues = $data['results'][$refId]['frames'][0]['data']['values'];
                if (is_array($rawValues) && isset($rawValues[0]) && is_array($rawValues[0])) {
                    foreach ($rawValues[0] as $val) {
                        $values[] = ['value' => $val, 'text' => $val];
                    }
                } elseif (is_array($rawValues)) {
                    foreach ($rawValues as $val) {
                        $values[] = ['value' => $val, 'text' => $val];
                    }
                }
            }
        }
        return $values;
    }

    private function httpRequest(string $method, string $url, ?string $body = null): ?string {
        $maxRetries = 2;
        $retryCount = 0;

        while ($retryCount <= $maxRetries) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->config->token,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $resp = curl_exec($ch);
            $err = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!$err && $code < 400) {
                return $resp;
            }

            $errorDetails = $err ?: "HTTP status $code";
            if ($code >= 400 && $resp) {
                $errorDetails .= ", Body: " . substr($resp, 0, 500);
            }

            $retryCount++;
            if ($retryCount <= $maxRetries && ($err || in_array($code, [500, 502, 503, 504]))) {
                sleep(1 * $retryCount);
            } else {
                return null;
            }
        }

        return null;
    }
}

// Класс QueryExtractor для извлечения запросов из панелей дашборда
class QueryExtractor {
    public function extractQueries($dashboard) {
        $queries = [];
        if (!isset($dashboard['panels'])) {
            return $queries;
        }
        foreach ($dashboard['panels'] as $panel) {
            $panelId = $panel['id'];
            $panelTitle = $panel['title'] ?? 'Panel ' . $panelId;
            $panelQueries = [];
            if (isset($panel['targets'])) {
                foreach ($panel['targets'] as $target) {
                    $query = null;
                    if (isset($target['expr'])) {
                        $query = $target['expr'];
                    } elseif (isset($target['query'])) {
                        $query = $target['query'];
                    }
                    if ($query) {
                        $panelQueries[] = $query;
                    }
                }
            }
            if (!empty($panelQueries)) {
                $queries[$panelId] = [
                    'title' => $panelTitle,
                    'queries' => $panelQueries
                ];
            }
        }
        return $queries;
    }

    public function extractVariablesFromQueries($queries) {
        $allVariables = [];
        foreach ($queries as $panelId => $panelData) {
            foreach ($panelData['queries'] as $query) {
                if (preg_match_all('/\$(\w+)/', $query, $matches)) {
                    foreach ($matches[1] as $var) {
                        $allVariables[$var] = true;
                    }
                }
            }
        }
        return array_keys($allVariables);
    }
}

// Функция для определения зависимостей переменных
function getDependencies($variables) {
    $deps = [];
    foreach ($variables as $var) {
        $deps[$var['name']] = [];
        if ($var['type'] === 'query') {
            $query = $var['query']['query'] ?? $var['query'];
            if (preg_match_all('/\$(\w+)/', $query, $matches)) {
                $deps[$var['name']] = $matches[1];
            }
        }
    }
    return $deps;
}

// Функция для топологической сортировки переменных
function topologicalSort($variables, $deps) {
    $sorted = [];
    $visited = [];
    $visiting = [];

    $visit = function($name, &$sorted, &$visited, &$visiting, $deps) use (&$visit) {
        if (isset($visited[$name])) return;
        if (isset($visiting[$name])) throw new Exception("Circular dependency detected for $name");
        $visiting[$name] = true;
        foreach ($deps[$name] as $dep) {
            $visit($dep, $sorted, $visited, $visiting, $deps);
        }
        $visiting[$name] = false;
        $visited[$name] = true;
        $sorted[] = $name;
    };

    foreach (array_keys($deps) as $name) {
        $visit($name, $sorted, $visited, $visiting, $deps);
    }
    return $sorted;
}

// Функция для генерации всех комбинаций переменных из дерева
function generateCombinations($tree, $varName) {
    $combinations = [];
    foreach ($tree as $value => $subtree) {
        if ($subtree === null) {
            $combinations[] = [$varName => $value];
        } elseif (is_array($subtree)) {
            $subCombs = [[]];
            foreach ($subtree as $depVar => $depTree) {
                $depCombs = generateCombinations($depTree, $depVar);
                $newSubCombs = [];
                foreach ($subCombs as $subComb) {
                    foreach ($depCombs as $depComb) {
                        $newSubCombs[] = array_merge($subComb, $depComb);
                    }
                }
                $subCombs = $newSubCombs;
            }
            foreach ($subCombs as $subComb) {
                $combinations[] = array_merge([$varName => $value], $subComb);
            }
        } else {
            $combinations[] = [$varName => $value];
        }
    }
    return $combinations;
}

// Основной поток выполнения
$config = new Config($url, $token, $dashboard_uid);
$api = new GrafanaAPI($config);
$parser = new VariableParser();

try {
    $fullDashboard = $api->getDashboard($dashboard_uid);
    $defaultDs = null;
    if (isset($fullDashboard['dashboard']['panels']) && count($fullDashboard['dashboard']['panels']) > 0) {
        $panelDs = $fullDashboard['dashboard']['panels'][0]['datasource'];
        $defaultDs = is_array($panelDs) ? ($panelDs['uid'] ?? null) : $panelDs;
    }
    $datasources = $api->getDatasources();
    $dsMap = [];
    foreach ($datasources as $ds) {
        $dsMap[$ds['uid']] = $ds['id'];
    }
    $defaultDsUid = null;
    foreach ($datasources as $ds) {
        if (isset($ds['isDefault']) && $ds['isDefault']) {
            $defaultDsUid = $ds['uid'];
            break;
        }
    }
    $fetcher = new DataFetcher($api, $config, $dsMap, $datasources, $defaultDsUid);
    $variables = $parser->parseVariables($fullDashboard['dashboard']);
    $varMap = [];
    foreach ($variables as $var) {
        $varMap[$var['name']] = $var;
    }
    foreach ($variables as &$variable) {
        if ($variable['type'] === 'query' && !isset($variable['datasource_type'])) {
            $refId = $variable['query']['refId'] ?? '';
            if (stripos($refId, 'Prometheus') !== false) {
                $variable['datasource_type'] = 'prometheus';
            } elseif (stripos($refId, 'Influx') !== false) {
                $variable['datasource_type'] = 'influxdb';
            } elseif (stripos($refId, 'ClickHouse') !== false) {
                $variable['datasource_type'] = 'grafana-clickhouse-datasource';
            } else {
                $variable['datasource_type'] = 'prometheus'; // default
            }
        }
        $varMap[$variable['name']] = $variable;
    }

$deps = getDependencies($variables);
$sortedNames = topologicalSort($variables, $deps);
$dependents = [];
foreach ($deps as $var => $depList) {
    foreach ($depList as $dep) {
        if (!isset($dependents[$dep])) $dependents[$dep] = [];
        $dependents[$dep][] = $var;
    }
}

    // Функция для построения дерева
    function buildTree($varName, $varMap, $dependents, $deps, $fetcher, $parentValue = null, $parentVar = null) {
        $variable = $varMap[$varName];
        $interpolatedQuery = null;
        if ($parentValue !== null && $parentVar !== null && $variable['type'] !== 'custom') {
            $query = $variable['query']['query'] ?? $variable['query'];
            $interpolatedQuery = str_replace('$' . $parentVar, $parentValue, $query);
        }
        try {
            $options = $fetcher->fetchOptions($variable, $variable['datasource_type'] ?? null, $interpolatedQuery);
        } catch (Exception $e) {
            echo "Error fetching options for $varName: " . $e->getMessage() . "\n";
            $options = [];
        }
        if (($variable['type'] === 'constant' || $variable['type'] === 'textbox') && empty($options) && isset($variable['query'])) {
            $values_parsed = array_map('trim', explode(',', $variable['query']));
            $options = array_map(function($v) { return ['value' => $v, 'text' => $v]; }, $values_parsed);
        }
        $values = array_map(function($opt) { return $opt['value']; }, $options);
        if ($variable['type'] === 'custom') {
            // Для custom переменных с пустыми dependencies возвращаем опции напрямую
            if (!isset($dependents[$varName]) || empty($dependents[$varName])) {
                return array_fill_keys($values, null);
            } else {
                // Для custom переменных с dependencies строим дерево с опциями как дочерними комбинациями
                $result = [];
                foreach ($values as $value) {
                    $subtree = [];
                    foreach ($dependents[$varName] as $depVar) {
                        $subtree[$depVar] = buildTree($depVar, $varMap, $dependents, $deps, $fetcher, $value, $varName);
                    }
                    $result[$value] = $subtree;
                }
                return $result;
            }
        } elseif ($variable['type'] === 'constant' || $variable['type'] === 'textbox') {
            // Для constant и textbox переменных аналогично custom
            if (!isset($dependents[$varName]) || empty($dependents[$varName])) {
                return array_fill_keys($values, null);
            } else {
                $result = [];
                foreach ($values as $value) {
                    $subtree = [];
                    foreach ($dependents[$varName] as $depVar) {
                        $subtree[$depVar] = buildTree($depVar, $varMap, $dependents, $deps, $fetcher, $value, $varName);
                    }
                    $result[$value] = $subtree;
                }
                return $result;
            }
        } else {
            // Логика для query переменных остается без изменений
            if (!isset($dependents[$varName]) || empty($dependents[$varName])) {
                return array_fill_keys($values, null);
            } else {
                $result = [];
                foreach ($values as $value) {
                    $subtree = [];
                    foreach ($dependents[$varName] as $depVar) {
                        $subtree[$depVar] = buildTree($depVar, $varMap, $dependents, $deps, $fetcher, $value, $varName);
                    }
                    $result[$value] = $subtree;
                }
                return $result;
            }
        }
    }

    // Построить дерево для custom, constant, textbox переменных и независимых query переменных
    $result = [];
    $roots = array_filter($variables, function($variable) use ($deps) {
        return ($variable['type'] === 'query' || $variable['type'] === 'custom' || $variable['type'] === 'constant' || $variable['type'] === 'textbox') && empty($deps[$variable['name']]);
    });
    foreach ($roots as $root) {
        $result[$root['name']] = buildTree($root['name'], $varMap, $dependents, $deps, $fetcher);
    }

    // Генерировать все комбинации
    $allCombinations = [];
    foreach ($result as $rootVar => $tree) {
        $combs = generateCombinations($tree, $rootVar);
        $allCombinations = array_merge($allCombinations, $combs);
    }

    // Извлечь запросы из панелей
    $extractor = new QueryExtractor();
    $panelQueries = $extractor->extractQueries($fullDashboard['dashboard']);

    // Обработать переменные из запросов: если не в templating, добавить как custom
    $varsFromQueries = $extractor->extractVariablesFromQueries($panelQueries);
    foreach ($varsFromQueries as $varName) {
        if (!isset($varMap[$varName])) {
            $newVar = [
                'name' => $varName,
                'type' => 'custom',
                'query' => '',
                'datasource' => null,
                'options' => []
            ];
            $variables[] = $newVar;
            $varMap[$varName] = $newVar;
        }
    }

    // Пересчитать зависимости после добавления новых переменных
    $deps = getDependencies($variables);
    $sortedNames = topologicalSort($variables, $deps);

    // Построить dependents
    $dependents = [];
    foreach ($deps as $var => $depList) {
        foreach ($depList as $dep) {
            if (!isset($dependents[$dep])) $dependents[$dep] = [];
            $dependents[$dep][] = $var;
        }
    }

    // Построить выходную структуру
    $output = [
        'variables' => array_map(function($var) {
            return [
                'name' => $var['name'],
                'type' => $var['type'],
                'options' => $var['options']
            ];
        }, $variables)
    ];
    foreach ($panelQueries as $panelId => $panelData) {
        $output[$panelId] = [
            'title' => $panelData['title'],
            'queries' => []
        ];
        foreach ($panelData['queries'] as $query) {
            $queryCombinations = [];
            if (preg_match_all('/\$(\w+)/', $query, $matches)) {
                $varsInQuery = $matches[1];
                foreach ($allCombinations as $comb) {
                    $valid = true;
                    foreach ($varsInQuery as $var) {
                        if (!isset($comb[$var])) {
                            $valid = false;
                            break;
                        }
                    }
                    if ($valid) {
                        $substituted = $query;
                        foreach ($comb as $var => $val) {
                            $substituted = str_replace('$' . $var, $val, $substituted);
                        }
                        $queryCombinations[] = [
                            'combination' => $comb,
                            'substituted_query' => $substituted
                        ];
                    }
                }
            } else {
                $queryCombinations[] = [
                    'combination' => [],
                    'substituted_query' => $query
                ];
            }
            $output[$panelId]['queries'][] = [
                'original' => $query,
                'datasource' => ['type' => 'prometheus', 'uid' => $defaultDsUid],
                'combinations' => $queryCombinations
            ];
        }
    }

    // Вывести структурированный JSON
    echo json_encode($output, JSON_PRETTY_PRINT) . "\n";
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
