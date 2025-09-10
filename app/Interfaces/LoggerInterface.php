<?php
declare(strict_types=1);

interface LoggerInterface
{
    public const LEVEL_DEBUG = 1;
    public const LEVEL_INFO = 2;
    public const LEVEL_WARN = 3;
    public const LEVEL_ERROR = 4;

    public function __construct(string $logFile, int $level);

    public function info(string $message, string $file, int $line): void;

    public function warn(string $message, string $file, int $line): void;

    public function error(string $message, string $file, int $line): void;

    public function debug(string $message, string $file, int $line): void;
}