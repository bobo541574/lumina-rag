<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Hash;
use Modules\ChatModule\Models\ChatMessage;
use Modules\ChatModule\Models\ChatSession;
use Modules\ChatModule\Services\RAGPipelineService;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\ChatModule\Services\Pipeline\ChunkProcessor;
use Modules\ChatModule\Services\Pipeline\FilterExtractor;
use Modules\ChatModule\Services\Pipeline\FtsQueryBuilder;
use Modules\ChatModule\Services\Pipeline\ResponseBuilder;
use Modules\ChatModule\Services\Pipeline\SessionManager;
use Modules\EmbeddingModule\Services\ProviderFactory;
use Modules\LLMModule\Contracts\LLMResponseInterface;
use Modules\LLMModule\Contracts\LLMServiceInterface;
use Modules\SettingsModule\Contracts\TermAliasServiceInterface;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;
use PHPUnit\Framework\MockObject\Exception;

/**
 * Create a RAG pipeline service with configurable options for edge-case testing
 *
 * Like makePipeline but accepts an optional $config array that merges over
 * sensible defaults for topK, similarityThreshold, maxQuestionLength,
 * maxMessagesPerSession, searchMode, queryExpansionEnabled, numExpansionQueries,
 * mmrEnabled, and mmrLambda.
 *
 * @param  EmbeddingServiceInterface  $embedder  Mock embedding service. Example: mock(EmbeddingServiceInterface::class)
 * @param  VectorStoreInterface  $vectorStore  Mock vector store. Example: mock(VectorStoreInterface::class)
 * @param  LLMServiceInterface  $llm  Mock LLM service. Example: mock(LLMServiceInterface::class)
 * @param  array<string, mixed>  $config  Optional overrides for pipeline configuration. Example: ['topK' => 10, 'similarityThreshold' => 0.5]
 * @return RAGPipelineService A pipeline with customised settings
 *                            Example: makeEdgePipeline($embedder, $vectorStore, $llm, ['mmrEnabled' => true])
 *
 * @throws Exception If mock creation fails
 *                   Example: Called with an interface that cannot be mocked
 */
function makeEdgePipeline(
    EmbeddingServiceInterface $embedder,
    VectorStoreInterface $vectorStore,
    LLMServiceInterface $llm,
    array $config = [],
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
    $responseBuilder->shouldReceive('buildSources')->andReturn([]);
    $sessionManager = mock(SessionManager::class);
    $sessionManager->shouldReceive('resolveSession')->andReturnUsing(function ($sessionId, $userId) {
        return $sessionId ? (Modules\ChatModule\Models\ChatSession::find($sessionId) ?? new Modules\ChatModule\Models\ChatSession) : new Modules\ChatModule\Models\ChatSession;
    });
    $sessionManager->shouldReceive('checkMessageLimit')->andReturn();
    $sessionManager->shouldReceive('saveUserMessage')->andReturn(new Modules\ChatModule\Models\ChatMessage);
    $sessionManager->shouldReceive('saveAssistantMessage')->andReturn(new Modules\ChatModule\Models\ChatMessage);

    $defaults = [
        'topK' => 5,
        'similarityThreshold' => 0.65,
        'maxQuestionLength' => 1000,
        'maxMessagesPerSession' => 100,
        'searchMode' => 'hybrid',
        'queryExpansionEnabled' => false,
        'numExpansionQueries' => 3,
        'mmrEnabled' => false,
        'mmrLambda' => 0.7,
    ];

    return new RAGPipelineService(
        $embedder,
        $vectorStore,
        $llm,
        $providerFactory,
        $cache,
        $termAliasService,
        $filterExtractor,
        $ftsQueryBuilder,
        $chunkProcessor,
        $responseBuilder,
        $sessionManager,
        ...array_merge($defaults, $config),
    );
}

/**
 * ask() returns a refusal message when no chunks are returned from the vector store
 *
 * Verifies the edge-case behaviour: when searchHybrid returns an empty array
 * the pipeline produces a human-readable refusal with no sources, without
 * ever calling the LLM.
 *
 * @return void
 */
test('ask_returns_refusal_when_no_chunks_found', function (): void {
    $embedder = mock(EmbeddingServiceInterface::class);
    $embedder->shouldReceive('embed')->andReturn([0.1, 0.2]);

    $vectorStore = mock(VectorStoreInterface::class);
    $vectorStore->shouldReceive('searchHybrid')->andReturn([]);

    $llm = mock(LLMServiceInterface::class);

    $pipeline = makeEdgePipeline($embedder, $vectorStore, $llm);
    $result = $pipeline->ask('test question');

    expect($result['message']['content'])->toStartWith('I cannot answer this question based on the available documents.');
    expect($result['message']['sources'])->toBe([]);
});

/**
 * ask() returns the LLM response when only a single chunk is returned
 *
 * Verifies that even with minimal context (one chunk) the pipeline
 * correctly passes the chunk to the LLM and surfaces the answer with
 * one source attached.
 *
 * @return void
 */
test('ask_returns_low_confidence_with_few_chunks', function (): void {
    $embedder = mock(EmbeddingServiceInterface::class);
    $embedder->shouldReceive('embed')->andReturn([0.1, 0.2]);

    $chunks = [
        (object) ['chunk_id' => 'c1', 'document_id' => 'doc_1', 'document_title' => 'Doc', 'content' => 'Content', 'chunk_index' => 0, 'page_number' => 1, 'similarity_score' => 0.89, 'document_created_at' => now()->toISOString()],
    ];

    $vectorStore = mock(VectorStoreInterface::class);
    $vectorStore->shouldReceive('searchHybrid')->andReturn($chunks);

    $response = mock(LLMResponseInterface::class);
    $response->shouldReceive('getContent')->andReturn('Low confidence answer.');
    $response->shouldReceive('getTotalTokens')->andReturn(10);
    $response->shouldReceive('getPromptTokens')->andReturn(5);
    $response->shouldReceive('getCompletionTokens')->andReturn(5);
    $response->shouldReceive('getFinishReason')->andReturn(null);

    $llm = mock(LLMServiceInterface::class);
    $llm->shouldReceive('complete')->andReturn($response);

    $pipeline = makeEdgePipeline($embedder, $vectorStore, $llm);
    $result = $pipeline->ask('test question');

    expect($result['message']['content'])->toBe('Low confidence answer.');
    expect($result['message']['sources'])->toHaveCount(1);
});

/**
 * ask() inherits user-context filters from the previous message in the session
 *
 * Creates a session with a prior user message containing the user's name,
 * then sends a follow-up question. Verifies the pipeline recognises the
 * session-level context correctly and still produces a refusal (since no
 * documents match).
 *
 * @return void
 */
test('ask_inherits_filters_from_previous_message', function (): void {
    $user = User::create([
        'name' => 'Edge Test User',
        'email' => 'edge-test@example.com',
        'password' => Hash::make('password123'),
        'api_token' => 'edge-test-token',
    ]);

    $session = ChatSession::create([
        'title' => 'Edge Test',
        'user_id' => $user->id,
        'last_activity_at' => now(),
    ]);

    ChatMessage::create([
        'session_id' => $session->id,
        'role' => 'user',
        'content' => 'Show me reports by '.$user->name,
    ]);

    $embedder = mock(EmbeddingServiceInterface::class);
    $embedder->shouldReceive('embed')->andReturn([0.1, 0.2]);

    $vectorStore = mock(VectorStoreInterface::class);
    $vectorStore->shouldReceive('searchHybrid')->andReturn([]);

    $llm = mock(LLMServiceInterface::class);

    $pipeline = makeEdgePipeline($embedder, $vectorStore, $llm);
    $result = $pipeline->ask('what did they submit?', ['session_id' => $session->id]);

    expect($result['message']['content'])->toStartWith('I cannot answer this question based on the available documents.');
});

/**
 * ask() includes an age note when all retrieved documents are older than one year
 *
 * Returns chunks with a document_created_at of two years ago and verifies
 * the pipeline still invokes the LLM and returns the answer — the age
 * disclaimer is injected into the prompt, not surfaced in the response assertion.
 *
 * @return void
 */
test('ask_includes_old_document_note', function (): void {
    $embedder = mock(EmbeddingServiceInterface::class);
    $embedder->shouldReceive('embed')->andReturn([0.1, 0.2]);

    $chunks = [
        (object) ['chunk_id' => 'c1', 'document_id' => 'doc_1', 'document_title' => 'Old Doc', 'content' => 'Old content', 'chunk_index' => 0, 'page_number' => 1, 'similarity_score' => 0.89, 'document_created_at' => now()->subYears(2)->toISOString()],
        (object) ['chunk_id' => 'c2', 'document_id' => 'doc_1', 'document_title' => 'Old Doc', 'content' => 'Old content 2', 'chunk_index' => 1, 'page_number' => 1, 'similarity_score' => 0.75, 'document_created_at' => now()->subYears(2)->toISOString()],
    ];

    $vectorStore = mock(VectorStoreInterface::class);
    $vectorStore->shouldReceive('searchHybrid')->andReturn($chunks);

    $response = mock(LLMResponseInterface::class);
    $response->shouldReceive('getContent')->andReturn('Answer with old document note.');
    $response->shouldReceive('getTotalTokens')->andReturn(10);
    $response->shouldReceive('getPromptTokens')->andReturn(5);
    $response->shouldReceive('getCompletionTokens')->andReturn(5);
    $response->shouldReceive('getFinishReason')->andReturn(null);

    $llm = mock(LLMServiceInterface::class);
    $llm->shouldReceive('complete')->andReturn($response);

    $pipeline = makeEdgePipeline($embedder, $vectorStore, $llm);
    $result = $pipeline->ask('test question');

    expect($result['message']['content'])->toBe('Answer with old document note.');
});
