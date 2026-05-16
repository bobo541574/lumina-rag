<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Hash;
use Modules\ChatModule\Models\ChatMessage;
use Modules\ChatModule\Models\ChatSession;
use Modules\ChatModule\Services\RAGPipelineService;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\EmbeddingModule\Services\ProviderFactory;
use Modules\LLMModule\Contracts\LLMResponseInterface;
use Modules\LLMModule\Contracts\LLMServiceInterface;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

function makeEdgePipeline(
    EmbeddingServiceInterface $embedder,
    VectorStoreInterface $vectorStore,
    LLMServiceInterface $llm,
    array $config = [],
): RAGPipelineService {
    $providerFactory = mock(ProviderFactory::class);
    $cache = mock(CacheRepository::class);

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
        ...array_merge($defaults, $config),
    );
}

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

    $llm = mock(LLMServiceInterface::class);
    $llm->shouldReceive('complete')->andReturn($response);

    $pipeline = makeEdgePipeline($embedder, $vectorStore, $llm);
    $result = $pipeline->ask('test question');

    expect($result['message']['content'])->toBe('Low confidence answer.');
    expect($result['message']['sources'])->toHaveCount(1);
});

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

    $llm = mock(LLMServiceInterface::class);
    $llm->shouldReceive('complete')->andReturn($response);

    $pipeline = makeEdgePipeline($embedder, $vectorStore, $llm);
    $result = $pipeline->ask('test question');

    expect($result['message']['content'])->toBe('Answer with old document note.');
});
