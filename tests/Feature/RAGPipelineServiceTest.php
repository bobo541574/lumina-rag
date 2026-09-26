<?php

declare(strict_types=1);

use Modules\ChatModule\Models\ChatMessage;
use Modules\ChatModule\Models\ChatSession;
use Modules\ChatModule\Services\Pipeline\ChunkProcessor;
use Modules\ChatModule\Services\Pipeline\FilterExtractor;
use Modules\ChatModule\Services\Pipeline\FtsQueryBuilder;
use Modules\ChatModule\Services\Pipeline\PipelineSettings;
use Modules\ChatModule\Services\Pipeline\QueryRewriterService;
use Modules\ChatModule\Services\Pipeline\ResponseBuilder;
use Modules\ChatModule\Services\Pipeline\RewrittenQuery;
use Modules\ChatModule\Services\Pipeline\SessionManager;
use Modules\ChatModule\Services\QuestionClassifier;
use Modules\ChatModule\Services\RAGPipelineService;
use Modules\ChatModule\Services\RerankerService;
use Modules\ChatModule\Services\SemanticCacheService;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\EmbeddingModule\Services\ProviderFactory;
use Modules\LLMModule\Contracts\LLMProviderInterface;
use Modules\LLMModule\Contracts\LLMResponseInterface;
use Modules\LLMModule\Contracts\LLMServiceInterface;
use Modules\SettingsModule\Contracts\TermAliasServiceInterface;
use Modules\SettingsModule\Models\AiModel;
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
    array $config = [],
): RAGPipelineService {
    $providerFactory = mock(ProviderFactory::class);
    $termAliasService = mock(TermAliasServiceInterface::class);
    $termAliasService->shouldReceive('expandText')->andReturnArg(0);
    $termAliasService->shouldReceive('expandFtsQuery')->andReturnArg(0);

    $filterExtractor = mock(FilterExtractor::class);
    $filterExtractor->shouldReceive('extract')->andReturn([]);
    $filterExtractor->shouldReceive('resolveTimeReferences')->andReturnArg(0);
    $ftsQueryBuilder = mock(FtsQueryBuilder::class);
    $ftsQueryBuilder->shouldReceive('refine')->andReturnArg(0);
    $ftsQueryBuilder->shouldReceive('constrainBoolean')->andReturnArg(0);
    $queryRewriter = mock(QueryRewriterService::class);
    $queryRewriter->shouldReceive('rewrite')->andReturnUsing(fn (string $q): RewrittenQuery => new RewrittenQuery(embeddingText: $q, ftsQuery: null));
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
        return $sessionId ? (ChatSession::find($sessionId) ?? new ChatSession) : new ChatSession;
    });
    $sessionManager->shouldReceive('checkMessageLimit')->andReturn();
    $sessionManager->shouldReceive('saveUserMessage')->andReturn(new ChatMessage);
    $sessionManager->shouldReceive('saveAssistantMessage')->andReturnUsing(function () {
        $msg = new ChatMessage;
        $msg->setAttribute('id', '01J');
        $msg->setAttribute('created_at', now());

        return $msg;
    });

    $reranker = mock(RerankerService::class);
    $reranker->shouldReceive('rerank')->andReturnArg(1);

    return new RAGPipelineService($embedder, $vectorStore, $llm, $providerFactory, $termAliasService, $filterExtractor, $ftsQueryBuilder, $queryRewriter, $chunkProcessor, $responseBuilder, $sessionManager, mock(SemanticCacheService::class), new QuestionClassifier, $reranker, new PipelineSettings(...$config));
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

/**
 * A factual (keyword-heavy) question routes to hybrid search even when the
 * configured global mode is 'vector'
 *
 * Adaptive retrieval routing: factual questions benefit from FTS + vector
 * fusion, so the pipeline forces hybrid mode regardless of the configured
 * searchMode. This test verifies searchHybrid is invoked (not search) for a
 * factual question under an explicit 'vector' configuration.
 *
 * @return void
 */
test('test_factual_question_routes_to_hybrid_in_vector_mode', function (): void {
    $embedder = mock(EmbeddingServiceInterface::class);
    $embedder->shouldReceive('embed')->andReturn([0.1, 0.2]);

    $chunks = [
        (object) [
            'chunk_id' => 'chunk_1',
            'document_id' => 'doc_1',
            'document_title' => 'Test Doc',
            'content' => 'Revenue was $45M.',
            'chunk_index' => 0,
            'page_number' => 1,
            'similarity_score' => 0.89,
        ],
    ];

    $vectorStore = mock(VectorStoreInterface::class);
    $vectorStore->shouldReceive('searchHybrid')->withArgs(function (string $ftsQuery, array $vector, int $topK, array $filters): bool {
        expect($topK)->toBe(15);

        return true;
    })->andReturn($chunks);
    // 'search' must NOT be called for a factual question in vector mode.
    $vectorStore->shouldReceive('search')->never();

    $response = mock(LLMResponseInterface::class);
    $response->shouldReceive('getContent')->andReturn('Revenue was $45M.');
    $response->shouldReceive('getTotalTokens')->andReturn(20);
    $response->shouldReceive('getPromptTokens')->andReturn(5);
    $response->shouldReceive('getCompletionTokens')->andReturn(15);
    $response->shouldReceive('getFinishReason')->andReturn(null);

    $llm = mock(LLMServiceInterface::class);
    $llm->shouldReceive('complete')->andReturn($response);

    $service = makePipeline($embedder, $vectorStore, $llm, ['searchMode' => 'vector']);
    $result = $service->ask('What is the total revenue?');

    expect($result['message']['content'])->toBe('Revenue was $45M.');
});

/**
 * ask() generates with the requested llm_model_id and that model's settings
 *
 * Regression: the sync path used to call the default LLM and default
 * temperature/max_tokens/num_ctx even when a different model was requested.
 *
 * @return void
 */
test('test_ask_uses_requested_llm_model_and_its_settings', function (): void {
    $embedder = mock(EmbeddingServiceInterface::class);
    $embedder->shouldReceive('embed')->andReturn([0.1, 0.2]);

    $vectorStore = mock(VectorStoreInterface::class);
    $vectorStore->shouldReceive('searchHybrid')->andReturn([
        (object) [
            'chunk_id' => 'chunk_1',
            'document_id' => 'doc_1',
            'document_title' => 'Test Doc',
            'content' => 'Test content',
            'chunk_index' => 0,
            'page_number' => 1,
            'similarity_score' => 0.89,
        ],
    ]);

    $defaultLlm = mock(LLMServiceInterface::class);
    $defaultLlm->shouldNotReceive('complete');

    // Built before the model exists so it is not picked as the default.
    $service = makePipeline($embedder, $vectorStore, $defaultLlm);

    $override = AiModel::create([
        'name' => 'Override LLM',
        'type' => 'llm',
        'provider' => 'ollama',
        'model' => 'override-model',
        'temperature' => 0.9,
        'max_context_tokens' => 8192,
        'settings' => ['max_tokens' => 777, 'think' => true],
        'is_active' => true,
    ]);

    $response = mock(LLMResponseInterface::class);
    $response->shouldReceive('getContent')->andReturn('Override answer.');
    $response->shouldReceive('getTotalTokens')->andReturn(10);
    $response->shouldReceive('getPromptTokens')->andReturn(5);
    $response->shouldReceive('getCompletionTokens')->andReturn(5);
    $response->shouldReceive('getFinishReason')->andReturn(null);

    $provider = mock(LLMProviderInterface::class);
    $provider->shouldReceive('countTokens')->andReturn(1);
    $provider->shouldReceive('complete')
        ->once()
        ->withArgs(fn (string $system, string $user, array $options): bool => $options['temperature'] === 0.9
            && $options['max_tokens'] === 777
            && $options['think'] === true
            && $options['num_ctx'] === 8192)
        ->andReturn($response);

    $factory = (new ReflectionProperty(RAGPipelineService::class, 'providerFactory'))->getValue($service);
    $factory->shouldReceive('createLLMProvider')->andReturn($provider);

    $result = $service->ask('test question', ['llm_model_id' => $override->id]);

    expect($result['message']['content'])->toBe('Override answer.');
});

function pipelineTestChunks(): array
{
    return [(object) [
        'chunk_id' => 'chunk_1',
        'document_id' => 'doc_1',
        'document_title' => 'Test Doc',
        'content' => 'Test content',
        'chunk_index' => 0,
        'page_number' => 1,
        'similarity_score' => 0.89,
    ]];
}

test('test_ask_stream_emits_events_in_order_and_streams_the_answer', function (): void {
    $embedder = mock(EmbeddingServiceInterface::class);
    $embedder->shouldReceive('embed')->andReturn([0.1, 0.2]);
    $vectorStore = mock(VectorStoreInterface::class);
    $vectorStore->shouldReceive('searchHybrid')->andReturn(pipelineTestChunks());

    $llm = mock(LLMServiceInterface::class);
    $llm->shouldReceive('completeStream')->once()->andReturnUsing(function (): Generator {
        yield 'Hello';
        yield ' world';
    });
    $llm->shouldReceive('countTokens')->andReturn(2);

    $events = array_map(
        fn (string $e): array => json_decode($e, true),
        iterator_to_array(makePipeline($embedder, $vectorStore, $llm)->askStream('test question'), false),
    );

    expect(array_column($events, 'type'))->toBe(['rewrite_info', 'status', 'status', 'sources', 'status', 'chunk', 'chunk', 'done']);
    expect($events[3]['sources'][0]['document_id'])->toBe('doc_1');
    expect($events[5]['content'].$events[6]['content'])->toBe('Hello world');
    expect($events[7]['tokens_used'])->toBe(2);
});

test('test_query_expansion_searches_every_reformulation', function (): void {
    $embedder = mock(EmbeddingServiceInterface::class);
    $embedder->shouldReceive('embed')->andReturn([0.1, 0.2]);
    $vectorStore = mock(VectorStoreInterface::class);
    $vectorStore->shouldReceive('searchHybrid')->times(3)->andReturn(pipelineTestChunks());

    $expansion = mock(LLMResponseInterface::class);
    $expansion->shouldReceive('getContent')->andReturn("first rewrite\nsecond rewrite");
    $answer = mock(LLMResponseInterface::class);
    $answer->shouldReceive('getContent')->andReturn('Answer.');
    $answer->shouldReceive('getFinishReason')->andReturn('stop');
    $answer->shouldReceive('getTotalTokens')->andReturn(5);
    $answer->shouldReceive('getPromptTokens')->andReturn(3);
    $answer->shouldReceive('getCompletionTokens')->andReturn(2);

    $llm = mock(LLMServiceInterface::class);
    $llm->shouldReceive('complete')
        ->withArgs(fn (string $system, string $user, array $context, array $options): bool => $system === '' && $context === [] && $options['max_tokens'] === 500)
        ->once()
        ->andReturn($expansion);
    $llm->shouldReceive('complete')
        ->withArgs(fn (string $system): bool => $system !== '')
        ->once()
        ->andReturn($answer);

    $result = makePipeline($embedder, $vectorStore, $llm, ['queryExpansionEnabled' => true, 'numExpansionQueries' => 2])
        ->ask('test question');

    expect($result['message']['content'])->toBe('Answer.');
});

test('test_ask_returns_semantic_cache_hit_without_searching', function (): void {
    $embedder = mock(EmbeddingServiceInterface::class);
    $embedder->shouldReceive('embed')->andReturn([0.1, 0.2]);
    $vectorStore = mock(VectorStoreInterface::class);
    $vectorStore->shouldNotReceive('searchHybrid');
    $llm = mock(LLMServiceInterface::class);
    $llm->shouldNotReceive('complete');

    $service = makePipeline($embedder, $vectorStore, $llm);
    $cache = (new ReflectionProperty(RAGPipelineService::class, 'answerCache'))->getValue($service);
    $semantic = (new ReflectionProperty($cache, 'semanticCache'))->getValue($cache);
    $semantic->shouldReceive('get')->andReturn(['question' => 'q', 'answer' => 'Cached answer.', 'sources' => [], 'is_refusal' => false]);

    $result = $service->ask('test question');

    expect($result['message']['content'])->toBe('Cached answer.');
    expect($result['message']['cached'])->toBeTrue();
});
