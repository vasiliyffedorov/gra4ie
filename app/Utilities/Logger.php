<?php
declare(strict_types=1);

class Logger implements LoggerInterface {
    private string $filePath;
    private int $logLevel;

    const LEVEL_DEBUG = 0;
    const LEVEL_INFO = 1;
    const LEVEL_WARN = 2;
    const LEVEL_ERROR = 3;

    private const LEVEL_MAP = [
        'debug' => self::LEVEL_DEBUG,
        'info' => self::LEVEL_INFO,
        'notice' => self::LEVEL_WARN,
        'warning' => self::LEVEL_WARN,
        'error' => self::LEVEL_ERROR,
        'critical' => self::LEVEL_ERROR,
        'alert' => self::LEVEL_ERROR,
        'emergency' => self::LEVEL_ERROR,
    ];

    public function __construct(string $filePath, int $logLevel) {
        $this->filePath = $filePath;
        $this->logLevel = $logLevel;
    }

    private function getFileAndLine(): array {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $trace[1] ?? ['file' => 'unknown.php', 'line' => 0];
        return [$caller['file'], $caller['line']];
    }

    private function rotateIfNeeded(): void {
        if (file_exists($this->filePath) && filesize($this->filePath) > 10 * 1024 * 1024) { // 10MB
            $oldLog = $this->filePath . '.' . date('Y-m-d_H-i-s') . '.log';
            rename($this->filePath, $oldLog);
        }
    }

    private function logInternal(string $message, ?string $file = null, ?int $line = null, int $level): void {
        if ($level < $this->logLevel) {
            return;
        }

        $this->rotateIfNeeded();

        [$defaultFile, $defaultLine] = $this->getFileAndLine();
        $file = $file ?? $defaultFile;
        $line = $line ?? $defaultLine;

        $levelStr = match ($level) {
            self::LEVEL_DEBUG => 'DEBUG',
            self::LEVEL_INFO => 'INFO',
            self::LEVEL_WARN => 'WARNING',
            self::LEVEL_ERROR => 'ERROR',
            default => 'UNKNOWN'
        };

        $entry = sprintf("[%s] %s %s:%d | %s\n",
            date('Y-m-d H:i:s'),
            $levelStr,
            basename($file),
            $line,
            $message
        );

        if (!file_put_contents($this->filePath, $entry, FILE_APPEND | LOCK_EX)) {
            error_log("Logger write failed: " . $entry);
        }
    }

    // PSR-3 methods
    public function log($level, $message, array $context = []): void {
        $intLevel = is_string($level) ? (self::LEVEL_MAP[strtolower($level)] ?? self::LEVEL_ERROR) : (int) $level;
        $contextStr = !empty($context) ? ' [context: ' . json_encode($context) . ']' : '';
        $fullMessage = (string) $message . $contextStr;
        $this->logInternal($fullMessage, null, null, $intLevel);
    }

    public function emergency($message, array $context = []): void {
        $this->log('emergency', $message, $context);
    }

    public function alert($message, array $context = []): void {
        $this->log('alert', $message, $context);
    }

    public function critical($message, array $context = []): void {
        $this->log('critical', $message, $context);
    }

    public function error($message, array $context = []): void {
        $this->log('error', $message, $context);
    }

    public function warning($message, array $context = []): void {
        $this->log('warning', $message, $context);
    }

    public function notice($message, array $context = []): void {
        $this->log('notice', $message, $context);
    }

    public function info($message, array $context = []): void {
        $this->log('info', $message, $context);
    }

    public function debug($message, array $context = []): void {
        $this->log('debug', $message, $context);
    }

    // Legacy methods for backward compatibility (additional methods)
    public function legacyDebug(string $message, string $file, int $line): void {
        $this->logInternal($message, $file, $line, self::LEVEL_DEBUG);
    }

    public function legacyInfo(string $message, string $file, int $line): void {
        $this->logInternal($message, $file, $line, self::LEVEL_INFO);
    }

    public function legacyWarn(string $message, string $file, int $line): void {
        $this->logInternal($message, $file, $line, self::LEVEL_WARN);
    }

    public function legacyError(string $message, string $file, int $line): void {
        $this->logInternal($message, $file, $line, self::LEVEL_ERROR);
    }
}
?>