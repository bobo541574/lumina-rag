<?php

declare(strict_types=1);

namespace App\Support;

/**
 * JSON-over-HTTP POST with retry, shared by the LLM and embedding providers.
 *
 * Retries transport errors and 5xx responses with the backoff configured in
 * `rag.http`; 4xx responses fail immediately since retrying cannot fix them.
 */
final class HttpJsonClient
{
    /**
     * POST a JSON payload and return the decoded JSON response.
     *
     * @param  string  $url  Endpoint URL. Example: "https://api.openai.com/v1/chat/completions"
     * @param  array<string>  $headers  Raw request headers; Content-Type is added. Example: ["Authorization: Bearer sk-..."]
     * @param  array  $payload  Request body. Example: ["model" => "gpt-4o", "messages" => [...]]
     * @param  int  $timeout  Per-attempt timeout in seconds. Example: 60
     * @param  string  $label  Provider name used in error messages. Example: "OpenAI"
     * @return array Decoded response body. Example: ["choices" => [...]]
     *
     * @throws \RuntimeException On a 4xx response, or when every attempt fails
     * @throws \JsonException When a 200 response is not valid JSON
     */
    public static function post(string $url, array $headers, array $payload, int $timeout, string $label): array
    {
        $backoffMs = (array) config('rag.http.retry_backoff_ms', [1000, 5000]);
        $maxAttempts = count($backoffMs) + 1;

        // Lift PHP's execution budget so the configured provider timeout, not
        // PHP's max_execution_time watchdog, governs the request. Without this,
        // a slow call on a model with timeout >= max_execution_time is killed by
        // an uncatchable FatalError instead of failing cleanly. Total worst case
        // is attempts * timeout plus backoff sleeps. 0 means unlimited (CLI).
        $currentLimit = (int) ini_get('max_execution_time');
        $neededLimit = $maxAttempts * $timeout + (int) array_sum($backoffMs) / 1000 + 60;
        if ($currentLimit !== 0 && $currentLimit < $neededLimit) {
            set_time_limit((int) $neededLimit);
        }

        $headers[] = 'Content-Type: application/json';
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            if ($error === '' && $httpCode === 200) {
                return json_decode((string) $response, true, 512, JSON_THROW_ON_ERROR);
            }

            if ($error === '' && $httpCode >= 400 && $httpCode < 500) {
                throw new \RuntimeException("{$label} API returned HTTP {$httpCode}: ".self::excerpt($response));
            }

            if ($attempt < $maxAttempts) {
                usleep((int) $backoffMs[$attempt - 1] * 1000);
            }
        }

        if ($error !== '') {
            throw new \RuntimeException("{$label} API request failed after {$maxAttempts} attempts: {$error}");
        }

        throw new \RuntimeException("{$label} API returned HTTP {$httpCode} after {$maxAttempts} attempts: ".self::excerpt($response));
    }

    /**
     * Shorten a response body for error messages and logs.
     *
     * @param  string|bool  $response  Raw body from curl_exec. Example: '{"error":"invalid key"}'
     */
    private static function excerpt(string|bool $response): string
    {
        return mb_substr(is_string($response) ? $response : '', 0, 500);
    }
}
