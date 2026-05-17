<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Modules\ChatModule\Services\RAGPipelineService;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\EmbeddingModule\Services\ProviderFactory;
use Modules\LLMModule\Contracts\LLMResponseInterface;
use Modules\LLMModule\Contracts\LLMServiceInterface;
use Modules\ChatModule\Services\Pipeline\ChunkProcessor;
use Modules\ChatModule\Services\Pipeline\FilterExtractor;
use Modules\ChatModule\Services\Pipeline\FtsQueryBuilder;
use Modules\ChatModule\Services\Pipeline\ResponseBuilder;
use Modules\ChatModule\Services\Pipeline\SessionManager;
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

    $filterExtractor = mock(FilterExtractor::class);
    $filterExtractor->shouldReceive('extract')->andReturn([]);
    $filterExtractor->shouldReceive('resolveTimeReferences')->andReturnArg(0);
    $ftsQueryBuilder = mock(FtsQueryBuilder::class);
    $ftsQueryBuilder->shouldReceive('refine')->andReturnArg(0);
    $chunkProcessor = mock(ChunkProcessor::class);
    $chunkProcessor->shouldReceive('applyDynamicThreshold')->andReturnArg(0);
    $chunkProcessor->shouldReceive('applyMMR')->andReturnArg(0);
    $chunkProcessor->shouldReceive('assessConfidence')->andReturn('high');
    $chunkProcessor->shouldReceive('hasOldDocuments')->andReturn(false);
    $chunkProcessor->shouldReceive('reorderForLostInTheMiddle')->andReturnArg(0);
    $responseBuilder = mock(ResponseBuilder::class);
    $responseBuilder->shouldReceive('buildSystemPrompt')->andReturn('system prompt');
    $responseBuilder->shouldReceive('buildFilterNote')->andReturn('');
    $responseBuilder->shouldReceive('buildRefusalResponse')->andReturn([
        'message' => ['content' => 'I cannot answer this question based on the available documents.', 'sources' => []],
    ]);
    $responseBuilder->shouldReceive('buildSources')->andReturnUsing(function (array $chunks): array {
        return array_map(fn (object $chunk): array => [
            'document_id' => $chunk->document_id,
            'document_title' => $chunk->document_title,
            'chunk_index' => $chunk->chunk_index ?? 0,
            'page_number' => $chunk->page_number ?? null,
            'similarity_score' => round((float) $chunk->similarity_score, 4),
            'excerpt' => mb_substr((string) $chunk->content, 0, 200),
        ], $chunks);
    });
    $sessionManager = mock(SessionManager::class);
    $sessionManager->shouldReceive('resolveSession')->andReturnUsing(function ($sessionId, $userId) {
        return $sessionId ? (Modules\ChatModule\Models\ChatSession::find($sessionId) ?? new Modules\ChatModule\Models\ChatSession) : new Modules\ChatModule\Models\ChatSession;
    });
    $sessionManager->shouldReceive('checkMessageLimit')->andReturn();
    $sessionManager->shouldReceive('saveUserMessage')->andReturn(new Modules\ChatModule\Models\ChatMessage);
    $sessionManager->shouldReceive('saveAssistantMessage')->andReturnUsing(function () {
        $msg = new Modules\ChatModule\Models\ChatMessage;
        $msg->setAttribute('id', '01J');
        $msg->setAttribute('created_at', now());

        return $msg;
    });

    return new RAGPipelineService($embedder, $vectorStore, $llm, $providerFactory, $cache, $termAliasService, $filterExtractor, $ftsQueryBuilder, $chunkProcessor, $responseBuilder, $sessionManager);
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
