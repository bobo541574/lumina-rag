<?php

declare(strict_types=1);

use Modules\LLMModule\Services\DeepSeekLLMProvider;
use Modules\LLMModule\Services\OllamaLLMProvider;

beforeAll(function (): void {
    $port = random_int(20000, 40000);
    $GLOBALS['llmStreamServer'] = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__.'/../Fixtures/stream_server.php'],
        [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
        $pipes,
    );
    $GLOBALS['llmStreamBase'] = "http://127.0.0.1:{$port}";

    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(50_000);
    }
});

afterAll(function (): void {
    proc_terminate($GLOBALS['llmStreamServer']);
});

test('openai_compatible_stream_yields_deltas', function (): void {
    $provider = new DeepSeekLLMProvider('key', 'deepseek-chat', 5, $GLOBALS['llmStreamBase'].'/ok');

    expect(implode('', iterator_to_array($provider->completeStream('s', 'u'), false)))->toBe('Hello world');
});

test('ollama_stream_yields_deltas_including_unterminated_last_line', function (): void {
    $provider = new OllamaLLMProvider($GLOBALS['llmStreamBase'].'/ok', 'llama3.2', 5);

    expect(implode('', iterator_to_array($provider->completeStream('s', 'u'), false)))->toBe('Hello world');
});

test('stream_http_error_throws_instead_of_returning_empty_answer', function (): void {
    $provider = new DeepSeekLLMProvider('bad-key', 'deepseek-chat', 5, $GLOBALS['llmStreamBase'].'/fail');

    expect(fn () => iterator_to_array($provider->completeStream('s', 'u')))
        ->toThrow(RuntimeException::class, 'DeepSeek streaming request failed (HTTP 401)');
});

test('stream_connection_failure_throws', function (): void {
    $provider = new DeepSeekLLMProvider('key', 'deepseek-chat', 2, 'http://127.0.0.1:1');

    expect(fn () => iterator_to_array($provider->completeStream('s', 'u')))
        ->toThrow(RuntimeException::class, 'DeepSeek streaming request failed');
});

test('stream_that_never_terminates_throws_a_catchable_timeout', function (): void {
    $provider = new DeepSeekLLMProvider('key', 'deepseek-chat', 1, $GLOBALS['llmStreamBase'].'/hang');

    $start = microtime(true);
    expect(fn () => iterator_to_array($provider->completeStream('s', 'u'), false))
        ->toThrow(RuntimeException::class);
    $caught = null;
    try {
        iterator_to_array($provider->completeStream('s', 'u'), false);
    } catch (RuntimeException $e) {
        $caught = $e->getMessage();
    }
    expect(str_contains(strtolower($caught), 'timeout'))->toBeTrue()
        ->and($caught)->not->toContain('Fatal error');
    expect(microtime(true) - $start)->toBeLessThan(10.0);
});
