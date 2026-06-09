<?php

declare(strict_types=1);

namespace Modules\ChatModule\Services;

use App\Models\User;
use Carbon\Carbon;
use Generator;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\ChatModule\Contracts\RAGPipelineServiceInterface;
use Modules\ChatModule\Models\ChatMessage;
use Modules\ChatModule\Models\ChatSession;
use Modules\ChatModule\Services\Pipeline\ChunkProcessor;
use Modules\ChatModule\Services\Pipeline\FilterExtractor;
use Modules\ChatModule\Services\Pipeline\FtsQueryBuilder;
use Modules\ChatModule\Services\Pipeline\QueryRewriterService;
use Modules\ChatModule\Services\Pipeline\ResponseBuilder;
use Modules\ChatModule\Services\Pipeline\SessionManager;
use Modules\DocumentModule\Models\Document;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\EmbeddingModule\Services\EmbeddingService;
use Modules\EmbeddingModule\Services\ProviderFactory;
use Modules\LLMModule\Contracts\LLMServiceInterface;
use Modules\LLMModule\Services\LLMService;
use Modules\SettingsModule\Contracts\TermAliasServiceInterface;
use Modules\SettingsModule\Models\AiModel;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

/**
 * RAG Pipeline Service
 *
 * Orchestrates the full RAG flow: embedding → vector/hybrid search → dynamic
 * threshold filtering → MMR diversity reranking → LLM completion. Supports
 * both synchronous (ask) and streaming (askStream) question answering.
 * Handles session lifecycle, message persistence, query expansion, time
 * reference resolution, Burmese/Myanmar language support, follow-up
 * context inheritance, and filter extraction from natural-language questions.
 *
 * All external dependencies (embedder, vector store, LLM, cache, provider
 * factory, alias service) are injected via constructor. Configuration values
 * are read from config/rag.php but can be overridden by active AiModel
 * settings stored in the database.
 *
 * @param  EmbeddingServiceInterface  $embedder  Converts question text to vector embeddings. Example: mock(EmbeddingServiceInterface::class)
 * @param  VectorStoreInterface  $vectorStore  Performs hybrid or pure vector search. Example: mock(VectorStoreInterface::class)
 * @param  LLMServiceInterface  $llm  Generates natural-language answers from context. Example: mock(LLMServiceInterface::class)
 * @param  ProviderFactory  $providerFactory  Creates provider instances per model config. Example: mock(ProviderFactory::class)
 * @param  CacheRepository  $cache  Cache backend for embedding and lookup caching. Example: $app->make(CacheRepository::class)
 * @param  TermAliasServiceInterface  $termAliasService  Expands aliases in search queries. Example: mock(TermAliasServiceInterface::class)
 * @param  int  $topK  Number of top chunks to retrieve. Example: 5
 * @param  float  $similarityThreshold  Minimum similarity for chunk inclusion. Example: 0.65
 * @param  int  $maxQuestionLength  Truncation limit for long questions. Example: 1000
 * @param  int  $maxMessagesPerSession  Hard limit per session. Example: 100
 * @param  string  $searchMode  "hybrid" or "vector". Example: "hybrid"
 * @param  bool  $queryExpansionEnabled  Enable LLM-based query reformulation. Example: false
 * @param  int  $numExpansionQueries  Number of reformulated queries. Example: 3
 * @param  bool  $mmrEnabled  Enable MMR diversity reranking. Example: true
 * @param  float  $mmrLambda  MMR diversity/lambda trade-off (0=only diversity, 1=only relevance). Example: 0.7
 * @param  int  $maxTokens  Max generation tokens. Example: 4096
 * @param  string|null  $userId  Authenticated user ULID for session scoping. Example: "01J..."
 * @param  string|null  $activeEmbeddingModelId  Override embedding model ULID. Example: "01J..."
 * @param  string|null  $activeLlmModelId  Override LLM model ULID. Example: "01J..."
 *
 * @throws \InvalidArgumentException If question is empty or exceeds maxQuestionLength
 *                                   Example: $service->ask('') → InvalidArgumentException("Question cannot be empty")
 * @throws \RuntimeException If no documents found, session expired, or message limit reached
 *                           Example: $service->ask('xyznonexistent') → RuntimeException
 */
class RAGPipelineService implements RAGPipelineServiceInterface
{
    private EmbeddingServiceInterface $embedder;

    private VectorStoreInterface $vectorStore;

    private LLMServiceInterface $llm;

    private int $topK;

    private float $similarityThreshold;

    private int $maxQuestionLength;

    private int $maxMessagesPerSession;

    private string $searchMode;

    private bool $queryExpansionEnabled;

    private int $numExpansionQueries;

    private bool $mmrEnabled;

    private float $mmrLambda;

    private int $maxTokens;

    private float $temperature;

    private ?bool $think;

    private ?string $userId;

    private ProviderFactory $providerFactory;

    private CacheRepository $cache;

    private ?AiModel $activeEmbeddingModel = null;

    private ?AiModel $activeLlmModel = null;

    private TermAliasServiceInterface $termAliasService;

    private FilterExtractor $filterExtractor;

    private FtsQueryBuilder $ftsQueryBuilder;

    private QueryRewriterService $queryRewriter;

    private ChunkProcessor $chunkProcessor;

    private ResponseBuilder $responseBuilder;

    private SessionManager $sessionManager;

    /**
     * Create a new RAGPipelineService instance
     *
     * All scalar configuration values have defaults from config/rag.php.
     * Active models are resolved from the AiModel registry; if explicit IDs
     * are provided they are used, otherwise the first active model by
     * sort_order is selected. Model-specific settings override the global
     * configuration (e.g. embedding top_k overrides $topK).
     *
     * @param  EmbeddingServiceInterface  $embedder  Vector embedding service. Example: mock(EmbeddingServiceInterface::class)
     * @param  VectorStoreInterface  $vectorStore  Vector store for similarity search. Example: mock(VectorStoreInterface::class)
     * @param  LLMServiceInterface  $llm  LLM service for answer generation. Example: mock(LLMServiceInterface::class)
     * @param  ProviderFactory  $providerFactory  Creates provider instances. Example: mock(ProviderFactory::class)
     * @param  CacheRepository  $cache  Cache repository. Example: $app->make(CacheRepository::class)
     * @param  TermAliasServiceInterface  $termAliasService  Term alias expansion. Example: mock(TermAliasServiceInterface::class)
     * @param  int  $topK  Number of chunks to retrieve. Example: 5
     * @param  float  $similarityThreshold  Minimum similarity. Example: 0.65
     * @param  int  $maxQuestionLength  Max question length. Example: 1000
     * @param  int  $maxMessagesPerSession  Max messages per session. Example: 100
     * @param  string  $searchMode  "hybrid" or "vector". Example: "hybrid"
     * @param  bool  $queryExpansionEnabled  Enable query expansion. Example: false
     * @param  int  $numExpansionQueries  Number of expanded queries. Example: 3
     * @param  bool  $mmrEnabled  Enable MMR reranking. Example: true
     * @param  float  $mmrLambda  MMR lambda parameter. Example: 0.7
     * @param  int  $maxTokens  Max tokens for LLM generation. Example: 4096
     * @param  string|null  $userId  User ULID for session scoping. Example: "01J..."
     * @param  string|null  $activeEmbeddingModelId  Override embedding model ULID. Example: null
     * @param  string|null  $activeLlmModelId  Override LLM model ULID. Example: null
     */
    public function __construct(
        EmbeddingServiceInterface $embedder,
        VectorStoreInterface $vectorStore,
        LLMServiceInterface $llm,
        ProviderFactory $providerFactory,
        CacheRepository $cache,
        TermAliasServiceInterface $termAliasService,
        FilterExtractor $filterExtractor,
        FtsQueryBuilder $ftsQueryBuilder,
        QueryRewriterService $queryRewriter,
        ChunkProcessor $chunkProcessor,
        ResponseBuilder $responseBuilder,
        SessionManager $sessionManager,
        int $topK = 5,
        float $similarityThreshold = 0.65,
        int $maxQuestionLength = 1000,
        int $maxMessagesPerSession = 100,
        string $searchMode = 'hybrid',
        bool $queryExpansionEnabled = false,
        int $numExpansionQueries = 3,
        bool $mmrEnabled = true,
        float $mmrLambda = 0.7,
        int $maxTokens = 4096,
        ?string $userId = null,
        ?string $activeEmbeddingModelId = null,
        ?string $activeLlmModelId = null,
    ) {
        $this->embedder = $embedder;
        $this->vectorStore = $vectorStore;
        $this->llm = $llm;
        $this->providerFactory = $providerFactory;
        $this->cache = $cache;
        $this->termAliasService = $termAliasService;
        $this->filterExtractor = $filterExtractor;
        $this->ftsQueryBuilder = $ftsQueryBuilder;
        $this->queryRewriter = $queryRewriter;
        $this->chunkProcessor = $chunkProcessor;
        $this->responseBuilder = $responseBuilder;
        $this->sessionManager = $sessionManager;
        $this->topK = $topK;
        $this->similarityThreshold = $similarityThreshold;
        $this->maxQuestionLength = $maxQuestionLength;
        $this->maxMessagesPerSession = $maxMessagesPerSession;
        $this->searchMode = $searchMode;
        $this->queryExpansionEnabled = $queryExpansionEnabled;
        $this->numExpansionQueries = $numExpansionQueries;
        $this->mmrEnabled = $mmrEnabled;
        $this->mmrLambda = $mmrLambda;
        $this->maxTokens = $maxTokens;
        $this->temperature = (float) config('rag.llm.temperature', 0.3);
        $this->think = null;
        $this->userId = $userId;

        try {
            if ($activeEmbeddingModelId !== null) {
                $this->activeEmbeddingModel = AiModel::find($activeEmbeddingModelId);
            }
            if ($this->activeEmbeddingModel === null) {
                $this->activeEmbeddingModel = AiModel::active()->embedding()->orderBy('sort_order')->first();
            }

            if ($activeLlmModelId !== null) {
                $this->activeLlmModel = AiModel::find($activeLlmModelId);
            }
            if ($this->activeLlmModel === null) {
                $this->activeLlmModel = AiModel::active()->llm()->orderBy('sort_order')->first();
            }
        } catch (\Throwable) {
            // DB not available yet
        }

        // Override settings from active models
        if ($this->activeEmbeddingModel !== null && $this->activeEmbeddingModel->settings !== null) {
            $s = $this->activeEmbeddingModel->settings;
            if (isset($s['top_k'])) {
                $this->topK = (int) $s['top_k'];
            }
            if (isset($s['similarity_threshold'])) {
                $this->similarityThreshold = (float) $s['similarity_threshold'];
            }
            if (isset($s['search_mode'])) {
                $this->searchMode = (string) $s['search_mode'];
            }
            if (isset($s['query_expansion_enabled'])) {
                $this->queryExpansionEnabled = (bool) $s['query_expansion_enabled'];
            }
            if (isset($s['num_expansion_queries'])) {
                $this->numExpansionQueries = (int) $s['num_expansion_queries'];
            }
            if (isset($s['mmr_enabled'])) {
                $this->mmrEnabled = (bool) $s['mmr_enabled'];
            }
            if (isset($s['mmr_lambda'])) {
                $this->mmrLambda = (float) $s['mmr_lambda'];
            }
        }
        if ($this->activeLlmModel !== null && $this->activeLlmModel->settings !== null) {
            $s = $this->activeLlmModel->settings;
            if (isset($s['max_question_length'])) {
                $this->maxQuestionLength = (int) $s['max_question_length'];
            }
            if (isset($s['max_messages_per_session'])) {
                $this->maxMessagesPerSession = (int) $s['max_messages_per_session'];
            }
            if (isset($s['max_tokens'])) {
                $this->maxTokens = (int) $s['max_tokens'];
            }
        }
        if ($this->activeLlmModel !== null && $this->activeLlmModel->temperature !== null) {
            $this->temperature = (float) $this->activeLlmModel->temperature;
        }
        if ($this->activeLlmModel !== null && $this->activeLlmModel->settings !== null) {
            $s = $this->activeLlmModel->settings;
            if (isset($s['think'])) {
                $this->think = (bool) $s['think'];
            }
        }
    }

    /**
     * Answer a question synchronously
     *
     * Embeds the question, searches for relevant chunks via vector or hybrid
     * search, applies dynamic threshold (elbow method) and MMR reranking,
     * then calls the LLM to generate an answer. Persists both user and
     * assistant messages to the session. Supports filter inheritance from
     * previous exchanges, query expansion, dynamic embedding model selection
     * per document filter, and conversation history injection.
     * Logs timing and token usage to the RAG log channel.
     *
     * @param  string  $question  The user's natural-language question. Example: "What is the revenue for Q3?"
     * @param  array  $options  Optional overrides. Example: ["session_id" => "01J...", "document_filter" => ["project" => "Orion"], "user_id" => "01J...", "llm_model_id" => "01J..."]
     * @return array{session_id: string, message: array} Response with session ID and assistant message
     *                                                   Example: ["session_id" => "01J...", "message" => ["id" => "01J...", "role" => "assistant", "content" => "Revenue was...", "sources" => [...], "tokens_used" => 150, "created_at" => "2026-05-17T10:00:00Z"]]
     *
     * @throws \InvalidArgumentException When question is empty or exceeds max length
     *                                   Example: $service->ask('') → InvalidArgumentException("Question cannot be empty")
     * @throws \RuntimeException When session not found, expired, message limit reached, or no chunks found
     *                           Example: $service->ask('question', ['session_id' => 'invalid']) → RuntimeException("Chat session not found.")
     */
    public function ask(string $question, array $options = []): array
    {
        set_time_limit(300);
        $start = microtime(true);
        $question = $this->normalizeQuestion($question);
        $session = $this->sessionManager->resolveSession($options['session_id'] ?? null, $options['user_id'] ?? $this->userId);
        $this->sessionManager->checkMessageLimit($session, $this->maxMessagesPerSession);

        $autoFilters = $this->filterExtractor->extract($question);

        // Rewrite query for improved search: spelling correction, date
        // expansion, synonym expansion, and boolean FTS query generation.
        $rewritten = $this->queryRewriter->rewrite($question);

        // Expand aliases for search: append canonical terms so vector search
        // (via expandText and expandQuery in the LLM query expansion step)
        // benefits from Burmese→English term mappings. The FTS path uses the
        // original question (not alias-expanded) to avoid introducing canonical
        // terms that don't appear in document chunk content.
        $searchQuestion = $this->termAliasService->expandText($rewritten->embeddingText);
        $ftsQuery = $this->ftsQueryBuilder->refine($rewritten->ftsQuery ?? $question, $autoFilters);
        $ftsQuery = $this->termAliasService->expandFtsQuery($ftsQuery);

        $this->sessionManager->saveUserMessage($session, $question);

        // Resolve relative time references (yesterday, today, …) to literal
        // dates so the LLM doesn't have to guess the current date.
        $llmQuestion = $this->filterExtractor->resolveTimeReferences($question);

        // Inherit filters from the previous exchange when the current
        // question doesn't specify any user or project (e.g. follow-ups).
        $prevAssistantMsg = ChatMessage::where('session_id', $session->id)
            ->where('role', 'assistant')
            ->orderBy('created_at', 'desc')
            ->first();

        $wasRefusal = $prevAssistantMsg !== null && empty($prevAssistantMsg->sources);

        $inherited = false;
        if (empty($autoFilters['user_ids']) && empty($autoFilters['project'])) {
            $prevUserMsg = ChatMessage::where('session_id', $session->id)
                ->where('role', 'user')
                ->orderBy('created_at', 'desc')
                ->skip(1)
                ->first(['content']);
            if ($prevUserMsg !== null) {
                $inherited = true;
                $inheritedFilters = $this->filterExtractor->extract($prevUserMsg->content);

                // Always inherit user and project
                if (isset($inheritedFilters['user_ids'])) {
                    $autoFilters['user_ids'] = $inheritedFilters['user_ids'];
                }
                if (isset($inheritedFilters['project'])) {
                    $autoFilters['project'] = $inheritedFilters['project'];
                }

                // Inherit dates ONLY if the previous answer was successful.
                // If it was a refusal, the previous dates likely caused the failure,
                // and the user is asking for alternatives (e.g. "what do you have then?").
                if (! $wasRefusal) {
                    if (isset($inheritedFilters['report_date_from']) && ! isset($autoFilters['report_date_from'])) {
                        $autoFilters['report_date_from'] = $inheritedFilters['report_date_from'];
                        $autoFilters['report_date_to'] = $inheritedFilters['report_date_to'];
                    }
                }
            }
        }

        // When the question had no filters of its own, also restrict the
        // search to the same documents cited in the previous answer. This
        // keeps follow-ups like "ဘာတွေတင်ထားလဲ?" on the exact same report.
        if ($inherited && ! $wasRefusal && $prevAssistantMsg !== null) {
            $sources = $prevAssistantMsg->sources;
            $ids = [];
            if (is_array($sources)) {
                foreach ($sources as $s) {
                    if (isset($s['document_id'])) {
                        $ids[] = $s['document_id'];
                    }
                }
            }
            if ($ids !== []) {
                $autoFilters['document_ids'] = $ids;
            }
        }

        $llm = $this->resolveLLM($options);

        // --- Dynamic Model Selection ---
        // If filters specifically target documents using a different embedding model,
        // use that model for the question embedding to ensure compatibility.
        $targetModel = $this->activeEmbeddingModel;
        $embedder = $this->embedder;

        if (! empty($autoFilters['user_ids']) || ! empty($autoFilters['project']) || ! empty($autoFilters['report_date_from'])) {
            try {
                $modelQuery = Document::query();
                if (! empty($autoFilters['user_ids'])) {
                    $modelQuery->whereIn('user_id', (array) $autoFilters['user_ids']);
                }
                if (! empty($autoFilters['project'])) {
                    $modelQuery->where('project', $autoFilters['project']);
                }
                if (! empty($autoFilters['report_date_from'])) {
                    $modelQuery->where('report_date', '>=', $autoFilters['report_date_from']);
                }
                if (! empty($autoFilters['report_date_to'])) {
                    $modelQuery->where('report_date', '<=', $autoFilters['report_date_to']);
                }

                $usedModelIds = $modelQuery->whereNotNull('embedding_model_id')
                    ->distinct()
                    ->pluck('embedding_model_id');

                if ($usedModelIds->count() === 1) {
                    $requiredId = $usedModelIds->first();
                    if ($this->activeEmbeddingModel === null || $this->activeEmbeddingModel->id !== $requiredId) {
                        $targetModel = AiModel::find($requiredId);
                        if ($targetModel !== null) {
                            $provider = $this->providerFactory->createEmbeddingProvider($targetModel);
                            $embedder = new EmbeddingService(
                                $provider,
                                $this->cache,
                                (int) ($targetModel->settings['cache_ttl'] ?? 86400)
                            );
                        }
                    }
                }
            } catch (\Throwable) {
                // Fallback to default model if anything fails
            }
        }

        $searchQueries = $this->expandQuery($searchQuestion, $llm, $options);

        $allChunks = [];
        $t0 = microtime(true);

        foreach ($searchQueries as $q) {
            $questionVector = $embedder->embed($q);
            // Use a very low threshold for the initial search to allow
            // applyDynamicThreshold (elbow method) to find the best gap.
            $minThreshold = 0.20;
            $filters = array_merge($autoFilters, $options['document_filter'] ?? []);
            $filters['similarity_threshold'] = $minThreshold;
            $filters['model_name'] = $targetModel?->model ?? config('rag.embedding.model', 'text-embedding-3-small');
            if ($rewritten->ftsQuery !== null) {
                $filters['boolean_fts_query'] = $rewritten->ftsQuery;
            }

            $chunks = $this->searchMode === 'hybrid'
                ? $this->vectorStore->searchHybrid($ftsQuery, $questionVector, $this->topK * 3, $filters)
                : $this->vectorStore->search($questionVector, $this->topK * 3, $filters);

            foreach ($chunks as $chunk) {
                $key = $chunk->chunk_id;
                if (! isset($allChunks[$key])) {
                    $allChunks[$key] = $chunk;
                }
            }
        }

        $searchTime = (microtime(true) - $t0) * 1000;

        usort($allChunks, fn (object $a, object $b): int => (float) $b->similarity_score <=> (float) $a->similarity_score);
        $chunks = $this->chunkProcessor->applyDynamicThreshold(array_values($allChunks), $autoFilters, $this->similarityThreshold, $this->topK);
        $chunks = $this->chunkProcessor->applyMMR($chunks, $this->mmrEnabled, $this->topK, $this->mmrLambda);

        if ($chunks === []) {
            $answer = $this->responseBuilder->buildRefusalResponse($session, $question, $autoFilters);
            $totalTime = (microtime(true) - $start) * 1000;
            Log::channel(config('rag.logging.channel', 'rag'))->info('RAG pipeline: refusal (no chunks)', [
                'session_id' => $session->id,
                'question_length' => mb_strlen($question),
                'rewrite_score' => $rewritten->score,
                'rewrite_mode' => $rewritten->mode,
                'search_time_ms' => round($searchTime, 1),
                'total_time_ms' => round($totalTime, 1),
            ]);

            return $answer;
        }

        $confidence = $this->chunkProcessor->assessConfidence($chunks);
        $hasOldDocs = $this->chunkProcessor->hasOldDocuments($chunks);
        $systemPrompt = $this->responseBuilder->buildSystemPrompt($confidence, $hasOldDocs);
        $context = $this->chunkProcessor->reorderForLostInTheMiddle($chunks);

        // Inject conversation history so the LLM can resolve follow-up
        // references (e.g. "what about last week?").
        if ($session->message_count > 1) {
            $history = ChatMessage::where('session_id', $session->id)
                ->orderBy('created_at', 'desc')
                ->skip(1)
                ->take(4)
                ->get(['role', 'content'])
                ->reverse();
            $conversation = '';
            foreach ($history as $msg) {
                $role = $msg->role === 'user' ? 'User' : 'Assistant';
                $conversation .= "{$role}: {$msg->content}\n\n";
            }
            $llmQuestion = "Previous conversation:\n{$conversation}Current question: {$llmQuestion}";
        }

        // Prepend search-scope metadata so the LLM knows which filters
        // were applied (e.g. date=2026-05-15, not its own guess of "yesterday").
        $scope = $this->responseBuilder->buildFilterNote($autoFilters);
        if ($scope !== '') {
            array_unshift($context, (object) [
                'document_title' => 'Search Scope',
                'content' => $scope,
                'page_number' => null,
            ]);
        }

        $t0 = microtime(true);
        $response = $this->llm->complete(
            systemPrompt: $systemPrompt,
            userPrompt: $llmQuestion,
            context: $context,
            options: $this->buildLlmOptions(['temperature' => $this->temperature, 'max_tokens' => $this->maxTokens], $options),
        );
        $llmTime = (microtime(true) - $t0) * 1000;

        $content = $response->getContent();
        if ($response->getFinishReason() === 'length') {
            $content .= "\n\n*[မှတ်ချက်: အဖြေသည် သတ်မှတ်ထားသော token ကန့်သတ်ချက်ကို ကျော်လွန်နေသောကြောင့် ဖြတ်တောက်ထားပါသည်။ ထပ်မံမေးမြန်းနိုင်ပါသည်။]*";
        }
        $sources = $this->responseBuilder->buildSources($chunks);
        $message = $this->sessionManager->saveAssistantMessage($session, $content, $sources);

        $totalTime = (microtime(true) - $start) * 1000;
        Log::channel(config('rag.logging.channel', 'rag'))->info('RAG pipeline: complete', [
            'session_id' => $session->id,
            'question_length' => mb_strlen($question),
            'rewrite_score' => $rewritten->score,
            'rewrite_mode' => $rewritten->mode,
            'chunks_found' => count($chunks),
            'search_time_ms' => round($searchTime, 1),
            'llm_time_ms' => round($llmTime, 1),
            'total_time_ms' => round($totalTime, 1),
            'prompt_tokens' => $response->getPromptTokens(),
            'completion_tokens' => $response->getCompletionTokens(),
            'total_tokens' => $response->getTotalTokens(),
        ]);

        return [
            'session_id' => $session->id,
            'message' => [
                'id' => $message->id,
                'role' => 'assistant',
                'content' => $response->getContent(),
                'sources' => $sources,
                'tokens_used' => $response->getTotalTokens(),
                'created_at' => $message->created_at->toIso8601String(),
            ],
        ];
    }

    /**
     * Answer a question with streaming SSE output
     *
     * Same RAG pipeline as ask() but yields JSON-encoded events for
     * Server-Sent Event delivery. Events: status (embedding/searching/generating
     * stages with bilingual messages), sources (document citations),
     * chunk (individual content tokens), and done (final metadata with
     * timing and token counts). Refusal responses are emitted as a
     * single chunk followed by done. Bilingual status messages are
     * provided in Burmese and English based on question content.
     *
     * @param  string  $question  The user's natural-language question. Example: "What is the revenue for Q3?"
     * @param  array  $options  Optional overrides. Example: ["session_id" => "01J...", "document_filter" => [], "user_id" => "01J...", "llm_model_id" => "01J..."]
     * @return Generator Yields JSON-encoded event strings for SSE delivery
     *                   Example: yield json_encode(['type' => 'chunk', 'content' => 'Revenue'])
     *                   Example: yield json_encode(['type' => 'done', 'session_id' => '01J...', 'search_time_ms' => 120.5, 'llm_time_ms' => 800.3])
     *
     * @throws \InvalidArgumentException When question is empty or exceeds max length
     *                                   Example: $service->askStream('') → InvalidArgumentException
     * @throws \RuntimeException When session not found, expired, or message limit reached
     *                           Example: $service->askStream('question', ['session_id' => 'invalid']) → RuntimeException
     */
    public function askStream(string $question, array $options = []): Generator
    {
        set_time_limit(300);
        $start = microtime(true);
        $question = $this->normalizeQuestion($question);
        $session = $this->sessionManager->resolveSession($options['session_id'] ?? null, $options['user_id'] ?? $this->userId);
        $this->sessionManager->checkMessageLimit($session, $this->maxMessagesPerSession);

        $autoFilters = $this->filterExtractor->extract($question);

        $rewritten = $this->queryRewriter->rewrite($question);

        $searchQuestion = $this->termAliasService->expandText($rewritten->embeddingText);
        $ftsQuery = $this->ftsQueryBuilder->refine($rewritten->ftsQuery ?? $question, $autoFilters);
        $ftsQuery = $this->termAliasService->expandFtsQuery($ftsQuery);

        $this->sessionManager->saveUserMessage($session, $question);

        // Resolve relative time references (yesterday, today, …) to literal
        // dates so the LLM doesn't have to guess the current date.
        $llmQuestion = $this->filterExtractor->resolveTimeReferences($question);

        // Inherit filters from the previous exchange when the current
        // question doesn't specify any user or project (e.g. follow-ups).
        $prevAssistantMsg = ChatMessage::where('session_id', $session->id)
            ->where('role', 'assistant')
            ->orderBy('created_at', 'desc')
            ->first();

        $wasRefusal = $prevAssistantMsg !== null && empty($prevAssistantMsg->sources);

        $inherited = false;
        if (empty($autoFilters['user_ids']) && empty($autoFilters['project'])) {
            $prevUserMsg = ChatMessage::where('session_id', $session->id)
                ->where('role', 'user')
                ->orderBy('created_at', 'desc')
                ->skip(1)
                ->first(['content']);
            if ($prevUserMsg !== null) {
                $inherited = true;
                $inheritedFilters = $this->filterExtractor->extract($prevUserMsg->content);

                // Always inherit user and project
                if (isset($inheritedFilters['user_ids'])) {
                    $autoFilters['user_ids'] = $inheritedFilters['user_ids'];
                }
                if (isset($inheritedFilters['project'])) {
                    $autoFilters['project'] = $inheritedFilters['project'];
                }

                // Inherit dates ONLY if the previous answer was successful.
                // If it was a refusal, the previous dates likely caused the failure,
                // and the user is asking for alternatives (e.g. "what do you have then?").
                if (! $wasRefusal) {
                    if (isset($inheritedFilters['report_date_from']) && ! isset($autoFilters['report_date_from'])) {
                        $autoFilters['report_date_from'] = $inheritedFilters['report_date_from'];
                        $autoFilters['report_date_to'] = $inheritedFilters['report_date_to'];
                    }
                }
            }
        }

        // When the question had no filters of its own, also restrict the
        // search to the same documents cited in the previous answer.
        if ($inherited && ! $wasRefusal && $prevAssistantMsg !== null) {
            $sources = $prevAssistantMsg->sources;
            $ids = [];
            if (is_array($sources)) {
                foreach ($sources as $s) {
                    if (isset($s['document_id'])) {
                        $ids[] = $s['document_id'];
                    }
                }
            }
            if ($ids !== []) {
                $autoFilters['document_ids'] = $ids;
            }
        }

        $llm = $this->resolveLLM($options);

        // --- Dynamic Model Selection ---
        // If filters specifically target documents using a different embedding model,
        // use that model for the question embedding to ensure compatibility.
        $targetModel = $this->activeEmbeddingModel;
        $embedder = $this->embedder;

        if (! empty($autoFilters['user_ids']) || ! empty($autoFilters['project']) || ! empty($autoFilters['report_date_from'])) {
            try {
                $modelQuery = Document::query();
                if (! empty($autoFilters['user_ids'])) {
                    $modelQuery->whereIn('user_id', (array) $autoFilters['user_ids']);
                }
                if (! empty($autoFilters['project'])) {
                    $modelQuery->where('project', $autoFilters['project']);
                }
                if (! empty($autoFilters['report_date_from'])) {
                    $modelQuery->where('report_date', '>=', $autoFilters['report_date_from']);
                }
                if (! empty($autoFilters['report_date_to'])) {
                    $modelQuery->where('report_date', '<=', $autoFilters['report_date_to']);
                }

                $usedModelIds = $modelQuery->whereNotNull('embedding_model_id')
                    ->distinct()
                    ->pluck('embedding_model_id');

                if ($usedModelIds->count() === 1) {
                    $requiredId = $usedModelIds->first();
                    if ($this->activeEmbeddingModel === null || $this->activeEmbeddingModel->id !== $requiredId) {
                        $targetModel = AiModel::find($requiredId);
                        if ($targetModel !== null) {
                            $provider = $this->providerFactory->createEmbeddingProvider($targetModel);
                            $embedder = new EmbeddingService(
                                $provider,
                                $this->cache,
                                (int) ($targetModel->settings['cache_ttl'] ?? 86400)
                            );
                        }
                    }
                }
            } catch (\Throwable) {
                // Fallback to default model if anything fails
            }
        }

        $isBurmese = preg_match('/[\x{1000}-\x{109F}]/u', $question) === 1;

        yield json_encode([
            'type' => 'rewrite_info',
            'score' => $rewritten->score,
            'mode' => $rewritten->mode,
            'embedding_text' => $rewritten->embeddingText,
            'fts_query' => $rewritten->ftsQuery,
        ]);

        yield json_encode([
            'type' => 'status',
            'stage' => 'embedding',
            'message' => $isBurmese ? 'မေးခွန်းအား ထည့်သွင်းနေသည်...' : 'Embedding question...',
        ]);

        $searchQueries = $this->expandQuery($searchQuestion, $llm, $options);
        $allChunks = [];
        $t0 = microtime(true);

        yield json_encode([
            'type' => 'status',
            'stage' => 'searching',
            'message' => $isBurmese ? 'စာရွက်စာတမ်းများတွင် ရှာဖွေနေသည်...' : 'Searching documents...',
        ]);

        foreach ($searchQueries as $q) {
            $questionVector = $embedder->embed($q);
            // Use a very low threshold for the initial search to allow
            // applyDynamicThreshold (elbow method) to find the best gap.
            $minThreshold = 0.20;
            $filters = array_merge($autoFilters, $options['document_filter'] ?? []);
            $filters['similarity_threshold'] = $minThreshold;
            $filters['model_name'] = $targetModel?->model ?? config('rag.embedding.model', 'text-embedding-3-small');
            if ($rewritten->ftsQuery !== null) {
                $filters['boolean_fts_query'] = $rewritten->ftsQuery;
            }

            $chunks = $this->searchMode === 'hybrid'
                ? $this->vectorStore->searchHybrid($ftsQuery, $questionVector, $this->topK * 3, $filters)
                : $this->vectorStore->search($questionVector, $this->topK * 3, $filters);

            foreach ($chunks as $chunk) {
                $key = $chunk->chunk_id;
                if (! isset($allChunks[$key])) {
                    $allChunks[$key] = $chunk;
                }
            }
        }

        $searchTime = (microtime(true) - $t0) * 1000;

        usort($allChunks, fn (object $a, object $b): int => (float) $b->similarity_score <=> (float) $a->similarity_score);
        $chunks = $this->chunkProcessor->applyDynamicThreshold(array_values($allChunks), $autoFilters, $this->similarityThreshold, $this->topK);
        $chunks = $this->chunkProcessor->applyMMR($chunks, $this->mmrEnabled, $this->topK, $this->mmrLambda);

        if ($chunks === []) {
            $refusal = $this->responseBuilder->buildRefusalResponse($session, $question, $autoFilters);
            $totalTime = (microtime(true) - $start) * 1000;
            Log::channel(config('rag.logging.channel', 'rag'))->info('RAG pipeline (stream): refusal (no chunks)', [
                'session_id' => $session->id,
                'question_length' => mb_strlen($question),
                'rewrite_score' => $rewritten->score,
                'rewrite_mode' => $rewritten->mode,
                'search_time_ms' => round($searchTime, 1),
                'total_time_ms' => round($totalTime, 1),
            ]);
            yield json_encode(['type' => 'sources', 'sources' => []]);
            yield json_encode(['type' => 'chunk', 'content' => $refusal['message']['content']]);
            yield json_encode([
                'type' => 'done',
                'session_id' => $session->id,
                'search_time_ms' => round($searchTime, 1),
                'llm_time_ms' => 0,
                'total_time_ms' => round($totalTime, 1),
                'tokens_used' => 0,
            ]);

            return;
        }

        $confidence = $this->chunkProcessor->assessConfidence($chunks);
        $hasOldDocs = $this->chunkProcessor->hasOldDocuments($chunks);
        $systemPrompt = $this->responseBuilder->buildSystemPrompt($confidence, $hasOldDocs);
        $context = $this->chunkProcessor->reorderForLostInTheMiddle($chunks);

        // Inject conversation history so the LLM can resolve follow-up
        // references (e.g. "what about last week?").
        if ($session->message_count > 1) {
            $history = ChatMessage::where('session_id', $session->id)
                ->orderBy('created_at', 'desc')
                ->skip(1)
                ->take(4)
                ->get(['role', 'content'])
                ->reverse();
            $conversation = '';
            foreach ($history as $msg) {
                $role = $msg->role === 'user' ? 'User' : 'Assistant';
                $conversation .= "{$role}: {$msg->content}\n\n";
            }
            $llmQuestion = "Previous conversation:\n{$conversation}Current question: {$llmQuestion}";
        }

        // Prepend search-scope metadata so the LLM knows which filters
        // were applied (e.g. date=2026-05-15, not its own guess of "yesterday").
        $scope = $this->responseBuilder->buildFilterNote($autoFilters);
        if ($scope !== '') {
            array_unshift($context, (object) [
                'document_title' => 'Search Scope',
                'content' => $scope,
                'page_number' => null,
            ]);
        }

        $sources = $this->responseBuilder->buildSources($chunks);
        yield json_encode(['type' => 'sources', 'sources' => $sources]);

        yield json_encode([
            'type' => 'status',
            'stage' => 'generating',
            'message' => $isBurmese ? 'အဖြေထုတ်လုပ်နေသည်...' : 'Generating answer...',
        ]);

        $fullContent = '';
        $t0 = microtime(true);
        $stream = $llm->completeStream($systemPrompt, $llmQuestion, $context, $this->buildLlmOptions([
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
        ], $options));

        foreach ($stream as $chunk) {
            $fullContent .= $chunk;
            yield json_encode(['type' => 'chunk', 'content' => $chunk]);
        }
        $llmTime = (microtime(true) - $t0) * 1000;

        $this->sessionManager->saveAssistantMessage($session, $fullContent, $sources);

        $totalTime = (microtime(true) - $start) * 1000;
        Log::channel(config('rag.logging.channel', 'rag'))->info('RAG pipeline (stream): complete', [
            'session_id' => $session->id,
            'question_length' => mb_strlen($question),
            'rewrite_score' => $rewritten->score,
            'rewrite_mode' => $rewritten->mode,
            'chunks_found' => count($chunks),
            'search_time_ms' => round($searchTime, 1),
            'llm_time_ms' => round($llmTime, 1),
            'total_time_ms' => round($totalTime, 1),
            'response_length' => mb_strlen($fullContent),
        ]);
        yield json_encode([
            'type' => 'done',
            'session_id' => $session->id,
            'search_time_ms' => round($searchTime, 1),
            'llm_time_ms' => round($llmTime, 1),
            'total_time_ms' => round($totalTime, 1),
            'tokens_used' => $llm->countTokens($fullContent),
        ]);
    }

    /**
     * List chat sessions with pagination
     *
     * Returns sessions ordered by last_activity_at descending, 20 per page.
     * Optionally scoped to a specific user via userId.
     *
     * @param  string|null  $userId  Filter to sessions owned by this user. Example: "01J..."
     * @param  int  $page  Page number (1-based). Example: 1
     * @return array{data: array, current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}
     *                                                                                                                        Paginated result matching Laravel's paginator toArray() format.
     *                                                                                                                        Example: ["data" => [...], "current_page" => 1, "last_page" => 3, "total" => 50]
     */
    public function listSessions(?string $userId = null, int $page = 1): array
    {
        $query = ChatSession::orderByDesc('last_activity_at');

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        return $query->paginate(20, ['*'], 'page', $page)->toArray();
    }

    /**
     * Get a single session with all messages
     *
     * Retrieves the session by ULID with its messages relation eager-loaded.
     * Optionally scoped to a specific user for ownership verification.
     *
     * @param  string  $id  The session ULID. Example: "01J..."
     * @param  string|null  $userId  Optional user ULID for ownership check. Example: "01J..."
     * @return array Session data with messages, as returned by Eloquent's toArray().
     *               Example: ["id" => "01J...", "title" => "New Chat", "messages" => [["id" => "01J...", "role" => "user", "content" => "hi"]]]
     *
     * @throws ModelNotFoundException When session is not found or not owned by the user
     *                                Example: $service->getSession('nonexistent') → ModelNotFoundException
     */
    public function getSession(string $id, ?string $userId = null): array
    {
        $query = ChatSession::with('messages')->where('id', $id);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $session = $query->firstOrFail();

        return $session->toArray();
    }

    /**
     * Delete a session and its messages
     *
     * Soft-deletes both the session and all associated messages.
     * Optionally scoped to a user for ownership verification.
     *
     * @param  string  $id  The session ULID to delete. Example: "01J..."
     * @param  string|null  $userId  Optional user ULID for ownership check. Example: "01J..."
     *
     * @throws ModelNotFoundException When session is not found or not owned by the user
     *                                Example: $service->deleteSession('nonexistent') → ModelNotFoundException
     */
    public function deleteSession(string $id, ?string $userId = null): void
    {
        $query = ChatSession::where('id', $id);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $session = $query->firstOrFail();
        $session->messages()->delete();
        $session->delete();
    }

    /**
     * Extract search filters from natural-language question text
     *
     * Parses the question for user names (matched against DB users table,
     * cached 5 min), project names (matched against distinct document projects,
     * cached 5 min), time periods (English and Myanmar: today, yesterday,
     * this_week, this_month, this_year, last_week, last_month, last_year),
     * Burmese month names (ဇန်နဝါရီ–ဒီဇင်ဘာ) with optional year, and date
     * patterns (YYYY-MM-DD, YYYY-MM, YYYY-MonthName, MonthName DD, quarters,
     * bare years). Burmese Unicode digits are normalised to ASCII for matching.
     * Returns an associative array of extracted filter values.
     *
     * @param  string  $question  The raw question text. Example: "What reports did John file in April 2026?"
     * @param  string  $question  The search question (may already be alias-expanded). Example: "Show me John's report for April"
     * @param  array  $filters  Extracted filters (used to strip user/project names). Example: ["user_ids" => ["01J..."], "report_date_from" => "2026-04-01"]
     * @param  array  $filters  Current filter array (may be empty). Example: []
     * @param  string  $period  Named time period. Example: "this_month"
     * @param  Carbon  $today  Reference date for relative calculations. Example: now()->startOfDay()
     * @param  string  $question  The question text potentially containing relative time references.
     *                            Example: "What reports were filed yesterday?"
     * @param  string  $question  The original search question. Example: "Q3 revenue report"
     * @param  LLMServiceInterface  $llm  The LLM service to generate reformulations. Example: $this->llm
     * @return array{user_ids?: array, project?: string, report_date_from?: string, report_date_to?: string}
     *                                                                                                       Extracted filters keyed by type. Example: ["user_ids" => ["01J..."], "project" => "Orion", "report_date_from" => "2026-04-01", "report_date_to" => "2026-04-30"]

    /**
     * Refine a question string into a clean FTS query
     *
     * Strips user names, project names, date/time patterns, stopwords
     * (English and Burmese conversational words), and short tokens from
     * the question, returning a space-separated string suitable for
     * PostgreSQL's plainto_tsquery. Handles Burmese Unicode by not using
     * \b word boundaries. Falls back to a minimal query ("report") if
     * all content words are stripped.
     * @return string Cleaned query string for plainto_tsquery. Example: "report April"

    /**
     * Apply a named time period to the filter array
     *
     * Converts shorthand period names (today, yesterday, this_week,
     * last_week, this_month, last_month, this_year, last_year) to
     * concrete report_date_from/report_date_to date ranges using
     * the provided reference date (today).
     * @return array Updated filters with report_date_from and report_date_to set.
     *               Example: ["report_date_from" => "2026-05-01", "report_date_to" => "2026-05-31"]

    /**
     * Replace relative time references with literal dates
     *
     * Substitutes relative time expressions (ဒီနေ့/today, မနေ့/yesterday,
     * ဒီတစ်လ/this month, ပြီးခဲ့တဲ့အပတ်/last week, etc.) in both Burmese
     * and English with their literal date ranges (e.g. "2026-05-17" or
     * "2026-05-11 to 2026-05-17"). This prevents the LLM from having to
     * guess the current date when answering time-sensitive questions.
     * @return string Question with relative times replaced by literal dates.
     *                Example: "What reports were filed on 2026-05-16?"

    /**
     * Expand a search query into multiple reformulations
     *
     * When query expansion is enabled, asks the LLM to generate
     * numExpansionQueries alternative reformulations of the question
     * to improve document retrieval recall. The original question is
     * prepended as the first query. If the LLM call fails, falls back
     * to returning only the original question.
     * @return array<string> Array of query strings, with the original first.
     *                       Example: ["Q3 revenue report", "third quarter revenue", "Q3 financial results"]
     */
    private function expandQuery(string $question, LLMServiceInterface $llm, array $requestOptions = []): array
    {
        if (! $this->queryExpansionEnabled) {
            return [$question];
        }

        try {
            $prompt = "You are a search query optimizer. Generate {$this->numExpansionQueries} different reformulations of the given question to improve document retrieval. Return ONE reformulation per line, no numbering, no extra text.\n\nQuestion: {$question}";

            $response = $llm->complete(
                systemPrompt: '',
                userPrompt: $prompt,
                context: '',
                options: $this->buildLlmOptions(['temperature' => $this->temperature, 'max_tokens' => 500], $requestOptions),
            );

            $lines = array_filter(
                explode("\n", $response->getContent()),
                fn (string $line): bool => trim($line) !== '',
            );

            $queries = array_slice(array_values($lines), 0, $this->numExpansionQueries);
            array_unshift($queries, $question);

            return $queries;
        } catch (\Throwable $e) {
            Log::channel(config('rag.logging.channel', 'rag'))->warning('Query expansion failed, using original question', [
                'error' => $e->getMessage(),
            ]);

            return [$question];
        }
    }

    /**
     * Reorder chunks using the "Lost in the Middle" strategy
     *
     * Places the highest-scoring chunk first and the lowest-scoring chunk
     * last, with the remaining chunks interleaved in the middle. This
     * mitigates the tendency of LLMs to lose information in the middle of
     * long contexts. For 2 or fewer chunks, returns the original order.
     *
     * @param  array  $chunks  Sorted chunks with similarity_score property.
     *                         Example: [['similarity_score' => 0.9, 'content' => '...'], ...]
     * @param  array  $chunks  Sorted chunks with similarity_score property.
     *                         Example: [['similarity_score' => 0.92, 'content' => '...'], ['similarity_score' => 0.45, 'content' => '...']]
     * @param  array  $filters  Current filters that may lower the base threshold.
     *                          Example: ["user_ids" => ["01J..."]]
     * @param  array  $chunks  Candidate chunks with document_id and similarity_score.
     *                         Example: [['document_id' => '01J...', 'similarity_score' => 0.9, 'content' => '...']]
     * @param  array  $filters  The active search filters.
     *                          Example: ["user_ids" => ["01J..."], "project" => "Orion", "report_date_from" => "2026-04-01", "report_date_to" => "2026-04-30"]
     * @param  string  $question  Raw question text. Example: "  What is revenue for 2026-04?  "
     * @return array Reordered chunks with best first, worst last.
     *               Example: [best, mid1, worst, mid2] for 4 chunks

    /**
     * Apply dynamic threshold using the elbow method
     *
     * Finds the largest gap between consecutive similarity scores and uses
     * it as a natural cutoff point. Falls back to scaling from the top
     * score (85%) when no significant gap exists (>0.15). When user/project/
     * date filters are present, uses a lower base threshold (0.45 instead
     * of the configured similarityThreshold) to account for narrower searches.
     * Always caps results at topK. If all chunks are below threshold but the
     * top score is >= 0.25, returns the single best chunk as a fallback.
     * @return array Filtered chunks above the determined threshold.
     *               Example: [['similarity_score' => 0.92, 'content' => '...']]

    /**
     * Apply Maximal Marginal Relevance (MMR) reranking
     *
     * Selects a diverse subset of chunks by balancing relevance (similarity_score)
     * against redundancy (same-document penalty). The first chunk is always the
     * highest-scoring; subsequent selections maximise mmr_lambda * relevance -
     * (1 - mmr_lambda) * max_similarity_to_selected. When two chunks share the
     * same document_id, the redundancy penalty is 1.0 (fully penalised).
     * Returns early (unchanged) when MMR is disabled or there is <= 1 chunk.
     * @return array MMR-diversified chunks capped at topK.
     *               Example: [best_chunk, diverse_chunk_from_other_doc, ...]

    /**
     * Build a human-readable filter description for the LLM context
     *
     * Converts the internal filters array into a text note listing
     * users (by name), project, and date/date range that was applied
     * during the search. This is prepended to the LLM context so the
     * model knows the exact scope used (e.g. a specific date rather
     * than the relative term "yesterday"). Returns empty string when
     * no filters are active.
     * @return string Formatted filter description, or empty string.
     *                Example: "Search scope:\n- Users: John Doe\n- Project: Orion\n- Date range: 2026-04-01 to 2026-04-30"

    /**
     * Normalise a question string
     *
     * Trims whitespace, validates non-empty, truncates to maxQuestionLength,
     * and converts bare YYYY-MM patterns (without a following day) to
     * YYYY-MonthName format so the LLM and date parser can recognise them
     * as month references rather than generic numbers.
     * @return string Normalised question. Example: "What is revenue for 2026-April?"
     *
     * @throws \InvalidArgumentException When question is empty after trimming.
     *                                   Example: normalizeQuestion('') → InvalidArgumentException("Question cannot be empty.")
     */
    private function normalizeQuestion(string $question): string
    {
        $question = trim($question);
        if ($question === '') {
            throw new \InvalidArgumentException('Question cannot be empty.');
        }
        if (mb_strlen($question) > $this->maxQuestionLength) {
            $question = mb_substr($question, 0, $this->maxQuestionLength);
        }

        $question = preg_replace_callback('/\b(\d{4})-(0[1-9]|1[0-2])\b(?!\s*-?\s*\d{1,2}\b)/', function ($m) {
            $months = ['January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'];

            return $m[1].'-'.$months[(int) $m[2] - 1];
        }, $question);

        return $question;
    }

    /**
     * Resolve a chat session from session ID or create a new one
     *
     * If a session_id is provided, looks up the existing session (scoped to
     * userId for ownership), validates it is not soft-deleted or expired
     * (>24h idle), updates last_activity_at, and returns it. If no session_id
     * is given, creates a new session with default title "New Chat".
     *
     * @param  string|null  $sessionId  Optional session ULID to resume. Example: "01J..."
     * @param  string|null  $userId  Optional user ULID for ownership scope. Example: "01J..."
     * @param  array  $options  Request options, possibly containing 'llm_model_id'.
     *                          Example: ["llm_model_id" => "01J..."]
     * @return ChatSession The resolved or newly created session.
    /**
     * Resolve the LLM service for a specific request
     *
     * If the options contain a valid llm_model_id pointing to an active LLM
     * AiModel, creates a new LLMService with the appropriate provider and
     * context token limit. Otherwise returns the default LLM service that
     * was injected at construction time.
     * @return LLMServiceInterface The resolved LLM service (default or overridden).
     *                             Example: $this->llm or new LLMService(...)
     */
    /**
     * Build LLM options array
     *
     * Merges base parameters (temperature, max_tokens) with the optional
     * `think` flag (for Ollama Qwen models). Precedence:
     * 1. Request-level `think` from $requestOptions (user toggles via API)
     * 2. AiModel settings `think` (configured per-model in DB)
     * 3. Auto-detect in OllamaLLMProvider (false for Qwen models)
     *
     * @param  array  $overrides  Base options (temperature, max_tokens, etc.).
     *                            Example: ['temperature' => 0.3, 'max_tokens' => 4096]
     * @param  array  $requestOptions  Pipeline-level options from the API request.
     *                                 Example: ['session_id' => '01J...', 'think' => true]
     * @return array Options with `think` conditionally included.
     *               Example: ['temperature' => 0.3, 'max_tokens' => 4096, 'think' => false]
     */
    private function buildLlmOptions(array $overrides, array $requestOptions = []): array
    {
        if (isset($requestOptions['think'])) {
            $overrides['think'] = (bool) $requestOptions['think'];
        } elseif ($this->think !== null) {
            $overrides['think'] = $this->think;
        }

        return $overrides;
    }

    /**
     * Resolve LLM Service
     *
     * Resolves the LLM service to use for a request. If a specific model ID
     * is provided in the options, it will create a new LLM service with that
     * model's provider. Otherwise, returns the default LLM service.
     *
     * @param  array  $options  Request options. Example: ["session_id" => "01J...", "llm_model_id" => "01J..."]
     * @return LLMServiceInterface The configured LLM service
     *                             Example: new LLMService(provider: new OllamaLLMProvider(...), maxContextTokens: 4000)
     */
    private function resolveLLM(array $options): LLMServiceInterface
    {
        $modelId = $options['llm_model_id'] ?? null;

        if ($modelId === null) {
            return $this->llm;
        }

        $aiModel = AiModel::find($modelId);

        if ($aiModel === null || ! $aiModel->is_active || $aiModel->type !== 'llm') {
            return $this->llm;
        }

        $provider = $this->providerFactory->createLLMProvider($aiModel);

        return new LLMService(
            provider: $provider,
            maxContextTokens: $aiModel->max_context_tokens ?? (int) config('rag.llm.max_context_tokens', 4000),
        );
    }
}
