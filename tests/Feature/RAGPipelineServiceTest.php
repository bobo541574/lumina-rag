<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Modules\ChatModule\Services\RAGPipelineService;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\EmbeddingModule\Services\ProviderFactory;
use Modules\LLMModule\Contracts\LLMResponseInterface;
use Modules\LLMModule\Contracts\LLMServiceInterface;
use Modules\SettingsModule\Contracts\TermAliasServiceInterface;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;
use PHPUnit\Framework\MockObject\Exception;

/**
 * Create a RAG pipeline service with mocked dependencies and defaults
 *
 * Builds a fully injected RAGPipelineService using the provided mock
 * services. TermAliasService is pre-configured to passthrough text and
 * FTS queries unchanged. Cache and ProviderFactory are unconfigured mocks.
 *
 * @param  EmbeddingServiceInterface  $embedder  Mock embedding service. Example: mock(EmbeddingServiceInterface::class)
 * @param  VectorStoreInterface  $vectorStore  Mock vector store. Example: mock(VectorStoreInterface::class)
 * @param  LLMServiceInterface  $llm  Mock LLM service. Example: mock(LLMServiceInterface::class)
 * @return RAGPipelineService A pipeline ready for testing
 *                            Example: makePipeline($embedder, $vectorStore, $llm)
 *
 * @throws Exception If mock creation fails
 *                   Example: Called with an interface that cannot be mocked
 */
function makePipeline(
    EmbeddingServiceInterface $embedder,
    VectorStoreInterface $vectorStore,
    LLMServiceInterface $llm,
): RAGPipelineService {
    $providerFactory = mock(ProviderFactory::class);
    $cache = mock(CacheRepository::class);
    $termAliasService = mock(TermAliasServiceInterface::class);
    $termAliasService->shouldReceive('expandText')->andReturnArg(0);
    $termAliasService->shouldReceive('expandFtsQuery')->andReturnArg(0);

    return new RAGPipelineService($embedder, $vectorStore, $llm, $providerFactory, $cache, $termAliasService);
}

/**
 * ask() throws InvalidArgumentException for an empty question
 *
 * Ensures the pipeline rejects empty input strings before
 * any embedding or search work is performed.
 *
 * @return void
 */
test('test_ask_with_empty_question_throws_exception', function (): void {
    $embedder = mock(EmbeddingServiceInterface::class);
    $vectorStore = mock(VectorStoreInterface::class);
    $llm = mock(LLMServiceInterface::class);

    $service = makePipeline($embedder, $vectorStore, $llm);

    expect(fn () => $service->ask(''))->toThrow(InvalidArgumentException::class);
});

/**
 * ask() returns a refusal message when the vector search yields no chunks
 *
 * Mocks the search to return an empty array and verifies the pipeline
 * produces a user-facing refusal without calling the LLM.
 *
 * @return void
 */
test('test_ask_returns_refusal_when_no_chunks_found', function (): void {
    $embedder = mock(EmbeddingServiceInterface::class);
    $embedder->shouldReceive('embed')->andReturn([0.1, 0.2]);

    $vectorStore = mock(VectorStoreInterface::class);
    $vectorStore->shouldReceive('searchHybrid')->andReturn([]);

    $llm = mock(LLMServiceInterface::class);

    $service = makePipeline($embedder, $vectorStore, $llm);
    $result = $service->ask('test question');

    expect($result['message']['content'])->toStartWith('I cannot answer this question based on the available documents.');
    expect($result['message']['sources'])->toBe([]);
});

/**
 * ask() returns an answer with sources when relevant chunks are found
 *
 * Mocks the full pipeline — embedding, hybrid search returning one chunk,
 * and LLM completing with an answer — then asserts the response content
 * and attached source metadata.
 *
 * @return void
 */
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
    $response->shouldReceive('getFinishReason')->andReturn(null);

    $llm = mock(LLMServiceInterface::class);
    $llm->shouldReceive('complete')->andReturn($response);

    $service = makePipeline($embedder, $vectorStore, $llm);
    $result = $service->ask('test question');

    expect($result['message']['content'])->toBe('This is the answer.');
    expect($result['message']['sources'])->toHaveCount(1);
    expect($result['message']['sources'][0]['document_id'])->toBe('doc_1');
});
