<?php
declare(strict_types=1);

namespace App\Processors;

use App\Interfaces\GrafanaVariableProcessorInterface;
use App\Interfaces\LoggerInterface;
use App\Utilities\HttpClient;

// Пользовательские исключения
class GrafanaAPIException extends \Exception {}
class VariableParseException extends \Exception {}
class DataFetchException extends \Exception {}
class CombinationException extends \Exception {}

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
    private HttpClient $httpClient;

    public function __construct(Config $config) {
        $this->config = $config;
        $this->httpClient = new HttpClient([
            'Authorization: Bearer ' . $this->config->token,
            'Content-Type: application/json'
        ], null);
    }


    public function searchDashboards($query, $type) {
        $url = $this->config->url . '/api/search?query=' . urlencode($query) . '&type=' . urlencode($type);
        $response = $this->httpClient->request('GET', $url);
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
        $response = $this->httpClient->request('GET', $url);
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
        $response = $this->httpClient->request('GET', $url);
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
            return []; // No variables, return empty array
        }
        $variables = [];
        foreach ($dashboard['templating']['list'] as $var) {
            $parsed = [
                'name' => $var['name'],
                'type' => $var['type'],
                'query' => isset($var['query']) ? $var['query'] : null,
                'datasource' => isset($var['datasource']) ? $var['datasource'] : null,
                'options' => isset($var['options']) ? $var['options'] : [],
                'regex' => isset($var['regex']) ? $var['regex'] : null
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
                    } elseif (stripos($refId, 'Elasticsearch') !== false || stripos($refId, 'Elastic') !== false) {
                        $parsed['datasource_type'] = 'elasticsearch';
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
    private HttpClient $httpClient;
    private LoggerInterface $logger;
    private int $maxOptionsPerVariable;

    public function __construct(GrafanaAPI $api, Config $config, $dsMap, LoggerInterface $logger, $dsNameMap = [], $maxOptionsPerVariable = 100) {
        $this->api = $api;
        $this->config = $config;
        $this->dsMap = $dsMap;
        $this->logger = $logger;
        $this->dsNameMap = $dsNameMap;
        $this->maxOptionsPerVariable = $maxOptionsPerVariable;
        $this->httpClient = new HttpClient([
            'Authorization: Bearer ' . $this->config->token,
            'Content-Type: application/json'
        ], $this->logger);
    }

    public function getDefaultDatasourceUid() {
        return 'deha3a4c2etq8d'; // prometheus
    }

    public function fetchOptions($variable, $datasource_type = null, $interpolatedQuery = null) {
        if ($variable['type'] !== 'query') {
            return $variable['options'];
        }
        $this->logger->debug("Fetching options for variable {$variable['name']}, type: {$variable['type']}, datasource_type: $datasource_type, query: " . json_encode($variable['query']));
        $url = $this->config->url . '/api/ds/query';
        $query = $interpolatedQuery ?? ($variable['query']['query'] ?? $variable['query']);
        $datasourceUid = is_array($variable['datasource']) ? $variable['datasource']['uid'] : $variable['datasource'];
        $this->logger->debug("datasourceUid for {$variable['name']}: " . json_encode($datasourceUid));
        // If datasourceUid is not in dsMap, try to find by name
        if (!isset($this->dsMap[$datasourceUid])) {
            $this->logger->debug("dsMap does not have $datasourceUid for {$variable['name']}, dsMap keys: " . json_encode(array_keys($this->dsMap)));
            if (isset($this->dsNameMap[$datasourceUid])) {
                $datasourceUid = $this->dsNameMap[$datasourceUid];
                $this->logger->debug("Found in dsNameMap, new datasourceUid: $datasourceUid");
            } else {
                $this->logger->debug("Not found in dsNameMap, returning empty for {$variable['name']}");
                return []; // Return empty options if datasource not found
            }
        }
        $datasourceId = $this->dsMap[$datasourceUid] ?? null;
        if ($datasourceId === null) {
            return []; // Return empty options if datasource not found
        }
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
            $this->logger->debug("Processing prometheus query: $query, datasource_type: $datasource_type");
            $expr = $query;
            $refId = "metricFindQuery";
            $rawQuery = false;
            $this->logger->debug("Initial expr: $expr, rawQuery: $rawQuery");
            $this->logger->debug("Final expr: $expr, rawQuery: $rawQuery");
            if (preg_match('/^label_values\((.+),\s*([^)]+)\)$/', $query, $matches)) {
                $metric = trim($matches[1]);
                $label = trim($matches[2]);
                if (empty($metric)) {
                    $this->logger->warning("Empty metric in label_values for variable, returning empty options");
                    return [];
                }
                $expr = "count by ($label) ($metric)";
                $rawQuery = true;
            } elseif (preg_match('/^query_result\((.+)\)$/', $query, $matches)) {
                $expr = trim($matches[1]);
                $rawQuery = true;
            } elseif (preg_match('/^label_values\(([^)]+)\)$/', $query, $matches)) {
                $label = trim($matches[1]);
                $expr = "count by ($label) ({})";
                $rawQuery = true;
            } elseif (preg_match('/^metrics\(([^)]+)\)$/', $query, $matches)) {
                $regex = stripslashes(trim($matches[1]));
                $expr = "count by (__name__) ({__name__=~\"$regex\"})";
                $rawQuery = true;
                $this->logger->debug("Matched metrics regex: $regex, new expr: $expr");
            } elseif (preg_match('/count by \(([^!~]+)!~([^}]+)\},([^)]+)\) \(([^)]+)\)/', $query, $matches)) {
                // Fix incorrect count by with !~ in label list
                $label = trim($matches[1]);
                $excludePattern = trim($matches[2]);
                $label2 = trim($matches[3]);
                $selector = trim($matches[4]);
                $expr = "count by ($label) ($selector,{$label2}!~$excludePattern)";
                $rawQuery = true;
                $this->logger->debug("Fixed count by !~ syntax: original $query -> $expr");
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
            $payload = [
                "queries" => [$queryObj],
                "from" => (string)((time() - 3600) * 1000),
                "to" => (string)((time() + 3600) * 1000)
            ];
        } elseif ($datasource_type === 'elasticsearch') {
            $queryObj = [
                "refId" => "A",
                "query" => $query,
                "datasource" => ["type" => "elasticsearch", "uid" => $datasourceUid],
                "datasourceId" => $datasourceId
            ];
            $payload = [
                "queries" => [$queryObj],
                "from" => (string)((time() - 3600) * 1000),
                "to" => (string)((time() + 3600) * 1000)
            ];
        } else {
            // Default to old behavior if needed, but according to task, only influxdb and prometheus
            $queryObj = [
                "refId" => "A",
                "datasource" => $variable['datasource'],
                "query" => $query
            ];
            $payload = [
                "queries" => [$queryObj],
                "from" => (string)((time() - 3600) * 1000),
                "to" => (string)((time() + 3600) * 1000)
            ];
        }
        $body = json_encode($payload);
        $this->logger->debug("About to send request for {$variable['name']}, url: $url, body length: " . strlen($body));
        $this->logger->debug("Sending to ds/query for variable {$variable['name']}: " . substr($body, 0, 200));
        $response = $this->httpClient->request('POST', $url, $body);
        $this->logger->debug("Response for variable {$variable['name']}: " . $response);
        if (!$response) {
            throw new DataFetchException('Failed to fetch options for variable ' . $variable['name'] . '. Request body: ' . $body);
        }
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new DataFetchException('JSON decode error: ' . json_last_error_msg());
        }
        $this->logger->debug("Parsed response data for {$variable['name']}: " . json_encode($data));
        // Check for errors in response
        if (isset($data['results']) && is_array($data['results'])) {
            foreach ($data['results'] as $result) {
                if (isset($result['error'])) {
                    $this->logger->warning("Datasource error for variable {$variable['name']}: " . $result['error']);
                    return []; // Return empty options on error
                }
            }
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
        $this->logger->debug("Extracted values for {$variable['name']}: " . json_encode($values));
        // Apply regex filtering if set
        if (isset($variable['regex']) && $variable['regex']) {
            $filtered = [];
            foreach ($values as $opt) {
                $val = (string)$opt['value'];
                if (@preg_match($variable['regex'], $val, $matches)) {
                    $newVal = isset($matches[1]) ? $matches[1] : $matches[0];
                    $filtered[] = ['value' => $newVal, 'text' => $newVal];
                }
            }
            $values = $filtered;
            $this->logger->debug("Filtered values for {$variable['name']} with regex {$variable['regex']}: " . json_encode($values));
        }
        return $values;
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
            $panelDatasource = $panel['datasource'] ?? null;
            if (isset($panel['targets'])) {
                foreach ($panel['targets'] as $target) {
                    $query = null;
                    if (isset($target['expr'])) {
                        $query = $target['expr'];
                    } elseif (isset($target['query'])) {
                        $query = $target['query'];
                    } elseif (isset($target['rawSql'])) {
                        $query = $target['rawSql'];
                    }
                    if ($query) {
                        $panelQueries[] = $query;
                    }
                }
            }
            if (!empty($panelQueries)) {
                $queries[$panelId] = [
                    'title' => $panelTitle,
                    'type' => $panel['type'] ?? 'unknown',
                    'datasource' => $panelDatasource,
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
                        if (!str_starts_with($var, '__')) {
                            $allVariables[$var] = true;
                        }
                    }
                }
            }
        }
        return array_keys($allVariables);
    }
}

class GrafanaVariableProcessor implements GrafanaVariableProcessorInterface
{
    private LoggerInterface $logger;
    private int $maxOptionsPerVariable;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    // Функция для определения зависимостей переменных
    private function getDependencies($variables) {
        $deps = [];
        foreach ($variables as $var) {
            $deps[$var['name']] = [];
            if ($var['type'] === 'query') {
                $query = $var['query']['query'] ?? $var['query'];
                if (is_string($query) && preg_match_all('/\$(\w+)/', $query, $matches)) {
                    $deps[$var['name']] = $matches[1];
                }
            }
        }
        // Инициализировать пустые массивы для всех зависимостей
        $allDeps = $deps;
        foreach ($deps as $name => $depList) {
            foreach ($depList as $dep) {
                if (!isset($allDeps[$dep])) {
                    $allDeps[$dep] = [];
                }
            }
        }
        return $allDeps;
    }

    // Функция для топологической сортировки переменных
    private function topologicalSort($variables, $deps) {
        $sorted = [];
        $visited = [];
        $visiting = [];

        $visit = function($name, &$sorted, &$visited, &$visiting, $deps) use (&$visit) {
            if (isset($visited[$name])) return;
            if (isset($visiting[$name])) throw new \Exception("Circular dependency detected for $name");
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
    private function generateCombinations($tree, $varName, $maxCombinations = 10000) {
        $combinations = [];
        foreach ($tree as $value => $subtree) {
            if (count($combinations) >= $maxCombinations) {
                $this->logger->warning("Reached max combinations limit ($maxCombinations) for variable $varName");
                break;
            }
            if ($subtree === null) {
                $combinations[] = [$varName => $value];
            } elseif (is_array($subtree)) {
                $subCombs = [[]];
                foreach ($subtree as $depVar => $depTree) {
                    $depCombs = $this->generateCombinations($depTree, $depVar, $maxCombinations - count($combinations));
                    if (empty($depCombs)) {
                        // If no combinations for dependency, skip
                        $subCombs = [];
                        break;
                    }
                    $newSubCombs = [];
                    foreach ($subCombs as $subComb) {
                        foreach ($depCombs as $depComb) {
                            if (count($newSubCombs) + count($combinations) >= $maxCombinations) {
                                break 2;
                            }
                            $newSubCombs[] = array_merge($subComb, $depComb);
                        }
                    }
                    $subCombs = $newSubCombs;
                }
                foreach ($subCombs as $subComb) {
                    if (count($combinations) >= $maxCombinations) {
                        break;
                    }
                    $combinations[] = array_merge([$varName => $value], $subComb);
                }
            } else {
                $combinations[] = [$varName => $value];
            }
        }
        return $combinations;
    }

    // Функция для построения дерева
    private function buildTree($varName, $varMap, $dependents, $deps, $fetcher, $parentValue = null, $parentVar = null) {
        $variable = $varMap[$varName];
        $interpolatedQuery = null;
        if ($parentValue !== null && $parentVar !== null && $variable['type'] !== 'custom') {
            $query = $variable['query']['query'] ?? $variable['query'];
            $interpolatedQuery = str_replace('$' . $parentVar, (string)$parentValue, $query);
        }
        try {
            $options = $fetcher->fetchOptions($variable, $variable['datasource_type'] ?? null, $interpolatedQuery);
        } catch (\Exception $e) {
            $this->logger->error("Error fetching options for $varName: " . $e->getMessage());
            $options = [];
        }
        $values = array_map(function($opt) { return $opt['value']; }, $options);
        // If no options, add empty string to generate at least one combination
        if (empty($values)) {
            $values = [""];
        }
        // Limit the number of options to prevent memory explosion
        if (count($values) > $this->maxOptionsPerVariable) {
            $values = array_slice($values, 0, $this->maxOptionsPerVariable);
            $this->logger->warning("Limited options for variable {$variable['name']} to {$this->maxOptionsPerVariable}");
        }
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
                        $subtree[$depVar] = $this->buildTree($depVar, $varMap, $dependents, $deps, $fetcher, $value, $varName);
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
                        $subtree[$depVar] = $this->buildTree($depVar, $varMap, $dependents, $deps, $fetcher, $value, $varName);
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
                        $subtree[$depVar] = $this->buildTree($depVar, $varMap, $dependents, $deps, $fetcher, $value, $varName);
                    }
                    $result[$value] = $subtree;
                }
                return $result;
            }
        }
    }

    public function processVariables(string $url, string $token, string $dashboardUid, int $maxOptionsPerVariable = 100, int $maxCombinations = 10000): array
    {
        $this->maxOptionsPerVariable = $maxOptionsPerVariable;
        $config = new Config($url, $token, $dashboardUid);
        $api = new GrafanaAPI($config);
        $parser = new VariableParser();
        $datasources = $api->getDatasources();
        $this->logger->info("Fetched datasources: " . json_encode($datasources));
        $dsMap = [];
        $dsNameMap = [];
        $defaultDs = null;
        foreach ($datasources as $ds) {
            $dsMap[$ds['uid']] = $ds['id'];
            $dsNameMap[$ds['name']] = $ds['uid'];
            if ($ds['isDefault']) {
                $defaultDs = $ds['uid'];
            }
        }
        $this->logger->info("defaultDs: $defaultDs, dsMap keys: " . json_encode(array_keys($dsMap)));
        if (!$defaultDs) {
            // Fallback to first prometheus datasource
            foreach ($datasources as $ds) {
                if ($ds['type'] === 'prometheus') {
                    $defaultDs = $ds['uid'];
                    break;
                }
            }
        }
        $fetcher = new DataFetcher($api, $config, $dsMap, $this->logger, $dsNameMap, $maxOptionsPerVariable);
        $getDsUidByType = function($type) use ($datasources, $defaultDs) {
            if ($type === 'prometheus' && $defaultDs) {
                // Use default datasource for Prometheus
                return $defaultDs;
            }
            foreach ($datasources as $ds) {
                if ($ds['type'] === $type) {
                    return $ds['uid'];
                }
            }
            return null;
        };

        try {
            $fullDashboard = $api->getDashboard($dashboardUid);
            $panelDs = null;
            if (isset($fullDashboard['dashboard']['panels']) && count($fullDashboard['dashboard']['panels']) > 0 && isset($fullDashboard['dashboard']['panels'][0]['datasource'])) {
                $panelDs = $fullDashboard['dashboard']['panels'][0]['datasource'];
                $panelDs = is_array($panelDs) ? ($panelDs['uid'] ?? null) : $panelDs;
            }
            if (!$panelDs) {
                $panelDs = $defaultDs;
            }
            $datasources = $api->getDatasources();
            $dsMap = [];
            foreach ($datasources as $ds) {
                $dsMap[$ds['uid']] = $ds['id'];
            }
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
                // Datasource устанавливается ниже на основе панелей
                $varMap[$variable['name']] = $variable;
            }

            $deps = $this->getDependencies($variables);
            $sortedNames = $this->topologicalSort($variables, $deps);
            $dependents = [];
            foreach ($deps as $var => $depList) {
                foreach ($depList as $dep) {
                    if (!isset($dependents[$dep])) $dependents[$dep] = [];
                    $dependents[$dep][] = $var;
                }
            }

// Для оставшихся query переменных без datasource установить default datasource
foreach ($variables as &$variable) {
    if ($variable['type'] === 'query' && !$variable['datasource']) {
        $variable['datasource'] = $defaultDs;
        // Установить datasource_type
        foreach ($datasources as $ds) {
            if ($ds['uid'] === $defaultDs) {
                $variable['datasource_type'] = $ds['type'];
                break;
            }
        }
        $varMap[$variable['name']] = $variable;
    }
}

// Построить дерево для custom, constant, textbox переменных и независимых query переменных
$result = [];
$roots = array_filter($variables, function($variable) use ($deps) {
    return ($variable['type'] === 'query' || $variable['type'] === 'custom' || $variable['type'] === 'constant' || $variable['type'] === 'textbox') && empty($deps[$variable['name']]);
});
foreach ($roots as $root) {
    $result[$root['name']] = $this->buildTree($root['name'], $varMap, $dependents, $deps, $fetcher);
}

// Генерировать все комбинации
$allCombinations = [];
foreach ($result as $rootVar => $tree) {
    $combs = $this->generateCombinations($tree, $rootVar, $maxCombinations - count($allCombinations));
    $allCombinations = array_merge($allCombinations, $combs);
    if (count($allCombinations) >= $maxCombinations) {
        $this->logger->warning("Stopped generating combinations at limit $maxCombinations");
        break;
    }
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

// Определить datasource для query переменных на основе панелей
$varDatasourceMap = [];
foreach ($panelQueries as $panelId => $panelData) {
    $panelDs = $panelData['datasource'] ?? $defaultDs;
    $dsUid = is_array($panelDs) ? ($panelDs['uid'] ?? null) : $panelDs;
    foreach ($panelData['queries'] as $query) {
        if (preg_match_all('/\$(\w+)/', $query, $matches)) {
            foreach ($matches[1] as $var) {
                if (!isset($varDatasourceMap[$var])) {
                    $varDatasourceMap[$var] = [];
                }
                $varDatasourceMap[$var][] = $dsUid;
            }
        }
    }
}
// Для каждого query переменной без datasource выбрать datasource из панелей
foreach ($variables as &$variable) {
    if ($variable['type'] === 'query' && !$variable['datasource'] && isset($varDatasourceMap[$variable['name']])) {
        $possibleDs = array_unique($varDatasourceMap[$variable['name']]);
        // Выбрать первый (или можно выбрать default если есть)
        $variable['datasource'] = $possibleDs[0] ?? $defaultDs;
        // Установить datasource_type если не установлен
        if (!isset($variable['datasource_type'])) {
            // Найти type по uid
            foreach ($datasources as $ds) {
                if ($ds['uid'] === $variable['datasource']) {
                    $variable['datasource_type'] = $ds['type'];
                    break;
                }
            }
        }
    }
}

            // Пересчитать зависимости после добавления новых переменных
            $deps = $this->getDependencies($variables);
            $sortedNames = $this->topologicalSort($variables, $deps);

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
                    'type' => $panelData['type'] ?? 'unknown',
                    'queries' => []
                ];
                $panelDs = $panelData['datasource'] ?? ['type' => 'prometheus', 'uid' => $defaultDs];
                if (is_array($panelDs)) {
                    $panelDsType = $panelDs['type'] ?? 'prometheus';
                    $panelDsUid = $panelDs['uid'] ?? $defaultDs;
                } else {
                    $panelDsType = 'prometheus'; // fallback
                    $panelDsUid = $panelDs ?: $defaultDs;
                }
                $this->logger->debug("Panel $panelId datasource: type=$panelDsType, uid=$panelDsUid");
                foreach ($panelData['queries'] as $query) {
                    $queryCombinations = [];
                    if (preg_match_all('/\$(\w+)/', $query, $matches)) {
                        $varsInQuery = $matches[1];
                        $hasRealVars = false;
                        foreach ($varsInQuery as $var) {
                            if (!str_starts_with($var, '__')) {
                                $hasRealVars = true;
                                break;
                            }
                        }
                        if (!$hasRealVars) {
                            $queryCombinations[] = [
                                'combination' => [],
                                'substituted_query' => $query
                            ];
                        } else {
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
                                        $substituted = str_replace('$' . $var, (string)$val, $substituted);
                                    }
                                    $queryCombinations[] = [
                                        'combination' => $comb,
                                        'substituted_query' => $substituted
                                    ];
                                }
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
                        'datasource' => ['type' => $panelDsType, 'uid' => $panelDsUid],
                        'combinations' => $queryCombinations
                    ];
                }
            }

            return ['output' => $output, 'allCombinations' => $allCombinations];
        } catch (\Exception $e) {
            $this->logger->error('Error in processVariables: ' . $e->getMessage());
            return [];
        }
    }
}