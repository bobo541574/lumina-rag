<?php

declare(strict_types=1);

use App\Support\HttpJsonClient;
use Modules\EmbeddingModule\Services\GeminiEmbeddingProvider;
use Modules\EmbeddingModule\Services\OllamaEmbeddingProvider;
use Modules\EmbeddingModule\Services\OpenAIEmbeddingProvider;
use Modules\LLMModule\Services\OllamaLLMProvider;
use Modules\LLMModule\Services\OpenAILLMProvider;

beforeAll(function (): void {
    $port = random_int(40001, 60000);
    $GLOBALS['providerHttpServer'] = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__.'/../Fixtures/stream_server.php'],
        [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
        $pipes,
    );
    $GLOBALS['providerHttpBase'] = "http://127.0.0.1:{$port}";

    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(50_000);
    }
});

afterAll(function (): void {
    proc_terminate($GLOBALS['providerHttpServer']);
});

beforeEach(function (): void {
    config(['rag.http.retry_backoff_ms' => [0, 0]]);
});

test('http_client_retries_server_errors_then_succeeds', function (): void {
    $id = bin2hex(random_bytes(6));

    $result = HttpJsonClient::post($GLOBALS['providerHttpBase']."/flaky/{$id}/api/embed", [], ['input' => ['a']], 5, 'Test');

    expect($result['embeddings'])->toBe([[0.1, 0.2]]);
});

test('http_client_fails_fast_on_client_errors', function (): void {
    expect(fn () => HttpJsonClient::post($GLOBALS['providerHttpBase'].'/fail', [], [], 5, 'Test'))
        ->toThrow(RuntimeException::class, 'Test API returned HTTP 401');
});

test('openai_compatible_llm_honours_base_url', function (): void {
    $provider = new OpenAILLMProvider('key', 'gpt-x', 5, $GLOBALS['providerHttpBase'].'/ok/v1', 'Compat');

    $response = $provider->complete('s', 'u');

    expect($response->getContent())->toBe('Hello world');
    expect($response->getTotalTokens())->toBe(5);
});

test('ollama_llm_complete_uses_shared_client', function (): void {
    $provider = new OllamaLLMProvider($GLOBALS['providerHttpBase'].'/ok', 'llama3.2', 5);

    expect($provider->complete('s', 'u')->getContent())->toBe('Hello world');
});

test('embedding_providers_batch_and_keep_input_order', function (): void {
    $openai = new OpenAIEmbeddingProvider('key', 'emb', 1, 5, 2, $GLOBALS['providerHttpBase'].'/ok/v1');
    $ollama = new OllamaEmbeddingProvider($GLOBALS['providerHttpBase'].'/ok', 'emb', 2, 5, 2);

    expect($openai->embedBatch(['a', 'b', 'c']))->toEqual([[0], [1], [0]]);
    expect($ollama->embedBatch(['a', 'b', 'c']))->toHaveCount(3);
    expect($ollama->embed('a'))->toBe([0.1, 0.2]);
});

test('gemini_embedding_sends_key_in_header_not_url', function (): void {
    $provider = new GeminiEmbeddingProvider('gemini-key', 'text-embedding-004', 2, 5, 100, $GLOBALS['providerHttpBase'].'/ok');

    expect($provider->embedBatch(['a', 'b']))->toBe([[0.3, 0.4], [0.3, 0.4]]);
});
