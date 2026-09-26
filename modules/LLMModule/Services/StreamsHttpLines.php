<?php

declare(strict_types=1);

namespace Modules\LLMModule\Services;

use Generator;

/**
 * Shared cURL streaming loop for LLM providers.
 *
 * Owns the multi-handle loop, line buffering, HTTP status check and transport
 * error reporting so each provider only supplies how one line of its stream
 * format maps to text deltas.
 */
trait StreamsHttpLines
{
    /**
     * POST a JSON payload and yield text deltas as response lines arrive.
     *
     * @param  string  $url  Streaming endpoint. Example: "https://api.openai.com/v1/chat/completions"
     * @param  array<string>  $headers  Raw request headers. Example: ["Content-Type: application/json"]
     * @param  array  $payload  JSON request body. Example: ["model" => "gpt-4o", "stream" => true]
     * @param  int  $timeout  cURL timeout in seconds. Example: 60
     * @param  string  $label  Provider name used in error messages. Example: "OpenAI"
     * @param  callable(string): iterable<string>  $parseLine  Maps one trimmed, non-empty line to zero or more deltas.
     *                                                         May throw \JsonException for malformed lines, which are skipped.
     * @return Generator Yields content strings. Example: foreach ($gen as $chunk) { echo $chunk; }
     *
     * @throws \RuntimeException When the server returns a non-200 status or the transfer fails
     */
    private function streamLines(string $url, array $headers, array $payload, int $timeout, string $label, callable $parseLine): Generator
    {
        $queue = [];
        $lineBuffer = '';
        $errorBody = '';
        $httpOk = null;

        $consume = function (string $line) use (&$queue, $parseLine): void {
            $line = trim($line);
            if ($line === '') {
                return;
            }

            try {
                foreach ($parseLine($line) as $delta) {
                    if ($delta !== '') {
                        $queue[] = $delta;
                    }
                }
            } catch (\JsonException) {
                // skip malformed JSON
            }
        };

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use (&$lineBuffer, &$errorBody, &$httpOk, $consume): int {
                $httpOk ??= curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;

                if (! $httpOk) {
                    $errorBody .= $data;

                    return strlen($data);
                }

                $lineBuffer .= $data;
                $lines = explode("\n", $lineBuffer);
                $lineBuffer = array_pop($lines);

                foreach ($lines as $line) {
                    $consume($line);
                }

                return strlen($data);
            },
        ]);

        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($status !== CURLM_OK) {
                break;
            }

            while ($queue !== []) {
                yield array_shift($queue);
            }

            if ($running > 0 && $queue === []) {
                usleep(5_000); // 5ms
            }
        } while ($running > 0);

        if ($httpOk === true && $lineBuffer !== '') {
            $consume($lineBuffer);
        }

        while ($queue !== []) {
            yield array_shift($queue);
        }

        $transportError = null;
        while (($info = curl_multi_info_read($mh)) !== false) {
            if ($info['result'] !== CURLE_OK) {
                $transportError = curl_strerror($info['result']);
            }
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_multi_remove_handle($mh, $ch);
        curl_multi_close($mh);

        if ($httpCode !== 0 && $httpCode !== 200) {
            throw new \RuntimeException("{$label} streaming request failed (HTTP {$httpCode}): ".mb_substr($errorBody, 0, 500));
        }
        if ($status !== CURLM_OK || $transportError !== null) {
            throw new \RuntimeException("{$label} streaming request failed: ".($transportError ?? curl_multi_strerror($status)));
        }
    }
}
