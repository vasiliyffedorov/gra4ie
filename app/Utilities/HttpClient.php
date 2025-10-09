<?php
declare(strict_types=1);

namespace App\Utilities;

use App\Interfaces\LoggerInterface;

class HttpClient
{
    private array $headers;
    private ?LoggerInterface $logger;
    private int $timeout;

    public function __construct(array $headers, ?LoggerInterface $logger = null, int $timeout = 30)
    {
        $this->headers = $headers;
        $this->logger = $logger;
        $this->timeout = $timeout;
    }

    public function request(string $method, string $url, ?string $body = null, ?int $timeout = null): ?string
    {
        $maxRetries = 2;
        $retryCount = 0;
        $timeout = $timeout ?? 30; // default 30 seconds

        while ($retryCount <= $maxRetries) {
            if ($this->logger) {
                $this->logger->info("HTTP Request → $method $url (attempt " . ($retryCount + 1) . ", timeout {$timeout}s)\nBody: " . ($body ?? 'none'));
            }
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $resp = curl_exec($ch);
            $err = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!$err && $code < 400) {
                if ($this->logger) {
                    $this->logger->info("HTTP Response ← Code: $code\nBody (truncated): " . substr($resp ?? '', 0, 1000));
                }
                return $resp;
            }

            $errorDetails = $err ?: "HTTP status $code";
            if ($code >= 400 && $resp) {
                $errorDetails .= ", Body: " . $resp;
            }
            if ($this->logger) {
                $this->logger->error("HTTP Error → $method $url (attempt " . ($retryCount + 1) . ")\nCode: $code, Error: $errorDetails");
            }

            // For client errors (4xx), return response to allow parsing errors
            if ($code >= 400 && $code < 500 && $resp) {
                return $resp;
            }

            $retryCount++;
            if ($retryCount <= $maxRetries && ($err || in_array($code, [500, 502, 503, 504]))) {
                if ($this->logger) {
                    $this->logger->info("Retrying request after " . ($retryCount - 1) . " failure(s)...");
                }
                sleep(1 * $retryCount);
            } else {
                return null;
            }
        }

        return null;
    }
}