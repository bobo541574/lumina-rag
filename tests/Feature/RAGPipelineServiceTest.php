<?php

declare(strict_types=1);

use Modules\ChatModule\Services\RAGPipelineService;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\LLMModule\Contracts\LLMResponseInterface;
use Modules\LLMModule\Contracts\LLMServiceInterface;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

test('test_ask_with_empty_question_throws_exception', function (): void {
    $embedder = mock(EmbeddingServiceInterface::class);
    $vectorStore = mock(VectorStoreInterface::class);
    $llm = mock(LLMServiceInterface::class);

    $service = new RAGPipelineService($embedder, $vectorStore, $llm);

    expect(fn () => $service->ask(''))->toThrow(InvalidArgumentException::class);
});

test('test_ask_returns_refusal_when_no_chunks_found', function (): void {
    $embedder = mock(EmbeddingServiceInterface::class);
    $embedder->shouldReceive('embed')->andReturn([0.1, 0.2]);

    $vectorStore = mock(VectorStoreInterface::class);
    $vectorStore->shouldReceive('searchHybrid')->andReturn([]);

    $llm = mock(LLMServiceInterface::class);

    $service = new RAGPipelineService($embedder, $vectorStore, $llm);
    $result = $service->ask('test question');

    expect($result['message']['content'])->toStartWith('I cannot answer this question based on the available documents.');
    expect($result['message']['sources'])->toBe([]);
});

test('test_ask_returns_answer_with_sources', function (): void {
    $embedder = mock(EmbeddingServiceInterface::class);
    $embedder->shouldReceive('embed')->andReturn([0.1, 0.2]);

    $chunks = [
        (object) [
            'chunk_id' => 'chunk_1',
            'document_id' => 'doc_1',
            'document_title' => 'Test Doc',
            'content' => 'Test content',
            'chunk_index' => 0,
            'page_number' => 1,
            'similarity_score' => 0.89,
        ],
    ];

    $vectorStore = mock(VectorStoreInterface::class);
    $vectorStore->shouldReceive('searchHybrid')->andReturn($chunks);

    $response = mock(LLMResponseInterface::class);
    $response->shouldReceive('getContent')->andReturn('This is the answer.');
    $response->shouldReceive('getTotalTokens')->andReturn(50);
    $response->shouldReceive('getPromptTokens')->andReturn(20);
    $response->shouldReceive('getCompletionTokens')->andReturn(30);

    $llm = mock(LLMServiceInterface::class);
    $llm->shouldReceive('complete')->andReturn($response);

    $service = new RAGPipelineService($embedder, $vectorStore, $llm);
    $result = $service->ask('test question');

    expect($result['message']['content'])->toBe('This is the answer.');
    expect($result['message']['sources'])->toHaveCount(1);
    expect($result['message']['sources'][0]['document_id'])->toBe('doc_1');
});
