<?php
declare(strict_types=1);

interface LoggerInterface
{
    // PSR-3 levels mapping (optional, for internal use)
    const LEVEL_DEBUG = 0;
    const LEVEL_INFO = 1;
    const LEVEL_WARN = 2;
    const LEVEL_ERROR = 3;

    public function log($level, $message, array $context = []): void;
    public function emergency($message, array $context = []): void;
    public function alert($message, array $context = []): void;
    public function critical($message, array $context = []): void;
    public function error($message, array $context = []): void;
    public function warning($message, array $context = []): void;
    public function notice($message, array $context = []): void;
    public function info($message, array $context = []): void;
    public function debug($message, array $context = []): void;
}