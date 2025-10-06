<?php
declare(strict_types=1);

namespace App\Utilities;

use App\Interfaces\LoggerInterface;

class HttpClient
{
    private array $headers;
    private ?LoggerInterface $logger;

    public function __construct(array $headers, ?LoggerInterface $logger = null)
    {
        $this->headers = $headers;
        $this->logger = $logger;
    }

    public function request(string $method, string $url, ?string $body = null): ?string
    {
        $maxRetries = 2;
        $retryCount = 0;

        while ($retryCount <= $maxRetries) {
            if ($this->logger) {
                $this->logger->info("HTTP Request → $method $url (attempt " . ($retryCount + 1) . ")\nBody: " . ($body ?? 'none'));
            }
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
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
                if ($this->logger) {
                    $this->logger->info("HTTP Response ← Code: $code\nBody (truncated): " . substr($resp ?? '', 0, 1000));
                }
                return $resp;
            }

            $errorDetails = $err ?: "HTTP status $code";
            if ($code >= 400 && $resp) {
                $errorDetails .= ", Body: " . substr($resp, 0, 500);
            }
            if ($this->logger) {
                $this->logger->error("HTTP Error → $method $url (attempt " . ($retryCount + 1) . ")\nCode: $code, Error: $errorDetails");
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