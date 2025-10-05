#!/usr/bin/env php
<?php

if ($argc < 2) {
    echo "Использование: php bin/search_metric_logs.php <имя_метрики>\n";
    exit(1);
}

$metric = $argv[1];
$logsDir = __DIR__ . '/../logs/';
$found = false;

if (!is_dir($logsDir)) {
    echo "Директория logs/ не найдена.\n";
    exit(1);
}

$files = scandir($logsDir);
foreach ($files as $file) {
    if ($file === '.' || $file === '..') {
        continue;
    }
    $filePath = $logsDir . $file;
    if (is_file($filePath)) {
        $content = file_get_contents($filePath);
        if ($content === false) {
            continue;
        }
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            if (strpos($line, $metric) !== false) {
                echo $line . "\n";
                $found = true;
            }
        }
    }
}

if (!$found) {
    echo "Метрика не найдена в логах\n";
}