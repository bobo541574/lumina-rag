<?php

declare(strict_types=1);

namespace Modules\ChatModule\Services;

use App\Models\User;
use Carbon\Carbon;
use Generator;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\ChatModule\Contracts\RAGPipelineServiceInterface;
use Modules\ChatModule\Models\ChatMessage;
use Modules\ChatModule\Models\ChatSession;
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

    private ?string $userId;

    private ProviderFactory $providerFactory;

    private CacheRepository $cache;

    private ?AiModel $activeEmbeddingModel = null;

    private ?AiModel $activeLlmModel = null;

    private TermAliasServiceInterface $termAliasService;

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
        set_time_limit(120);
        $start = microtime(true);
        $question = $this->normalizeQuestion($question);
        $session = $this->resolveSession($options['session_id'] ?? null, $options['user_id'] ?? $this->userId);
        $this->checkMessageLimit($session);

        $autoFilters = $this->extractFiltersFromQuestion($question);

        // Expand aliases for search: append canonical terms so both
        // vector (via expandText) and FTS (via expandFtsQuery) find matches.
        $searchQuestion = $this->termAliasService->expandText($question);
        $ftsQuery = $this->refineFtsQuery($searchQuestion, $autoFilters);
        $ftsQuery = $this->termAliasService->expandFtsQuery($ftsQuery);

        $this->saveUserMessage($session, $question);

        // Resolve relative time references (yesterday, today, …) to literal
        // dates so the LLM doesn't have to guess the current date.
        $llmQuestion = $this->resolveTimeReferences($question);

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
                $inheritedFilters = $this->extractFiltersFromQuestion($prevUserMsg->content);

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

        $searchQueries = $this->expandQuery($searchQuestion, $llm);

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
        $chunks = $this->applyDynamicThreshold(array_values($allChunks), $autoFilters);
        $chunks = $this->applyMMR($chunks);

        if ($chunks === []) {
            $answer = $this->buildRefusalResponse($session, $question, $autoFilters);
            $totalTime = (microtime(true) - $start) * 1000;
            Log::channel(config('rag.logging.channel', 'rag'))->info('RAG pipeline: refusal (no chunks)', [
                'session_id' => $session->id,
                'question_length' => mb_strlen($question),
                'search_time_ms' => round($searchTime, 1),
                'total_time_ms' => round($totalTime, 1),
            ]);

            return $answer;
        }

        $confidence = $this->assessConfidence($chunks);
        $hasOldDocs = $this->hasOldDocuments($chunks);
        $systemPrompt = $this->buildSystemPrompt($confidence, $hasOldDocs);
        $context = $this->reorderForLostInTheMiddle($chunks);

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
        $scope = $this->buildFilterNote($autoFilters);
        if ($scope !== '') {
            array_unshift($context, (object) [
                'document_title' => 'Search Scope',
                'content' => $scope,
                'page_number' => null,
            ]);
        }

        $t0 = microtime(true);
        $response = $llm->complete($systemPrompt, $llmQuestion, $context, [
            'temperature' => 0.3,
            'max_tokens' => $this->maxTokens,
        ]);
        $llmTime = (microtime(true) - $t0) * 1000;

        $content = $response->getContent();
        if ($response->getFinishReason() === 'length') {
            $content .= "\n\n*[မှတ်ချက်: အဖြေသည် သတ်မှတ်ထားသော token ကန့်သတ်ချက်ကို ကျော်လွန်နေသောကြောင့် ဖြတ်တောက်ထားပါသည်။ ထပ်မံမေးမြန်းနိုင်ပါသည်။]*";
        }

        $sources = $this->buildSources($chunks);
        $message = $this->saveAssistantMessage($session, $content, $sources);

        $totalTime = (microtime(true) - $start) * 1000;
        Log::channel(config('rag.logging.channel', 'rag'))->info('RAG pipeline: complete', [
            'session_id' => $session->id,
            'question_length' => mb_strlen($question),
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
        set_time_limit(120);
        $start = microtime(true);
        $question = $this->normalizeQuestion($question);
        $session = $this->resolveSession($options['session_id'] ?? null, $options['user_id'] ?? $this->userId);
        $this->checkMessageLimit($session);

        $autoFilters = $this->extractFiltersFromQuestion($question);

        $searchQuestion = $this->termAliasService->expandText($question);
        $ftsQuery = $this->refineFtsQuery($searchQuestion, $autoFilters);
        $ftsQuery = $this->termAliasService->expandFtsQuery($ftsQuery);

        $this->saveUserMessage($session, $question);

        // Resolve relative time references (yesterday, today, …) to literal
        // dates so the LLM doesn't have to guess the current date.
        $llmQuestion = $this->resolveTimeReferences($question);

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
                $inheritedFilters = $this->extractFiltersFromQuestion($prevUserMsg->content);

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
            'type' => 'status',
            'stage' => 'embedding',
            'message' => $isBurmese ? 'မေးခွန်းအား ထည့်သွင်းနေသည်...' : 'Embedding question...',
        ]);

        $searchQueries = $this->expandQuery($searchQuestion, $llm);
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
        $chunks = $this->applyDynamicThreshold(array_values($allChunks), $autoFilters);
        $chunks = $this->applyMMR($chunks);

        if ($chunks === []) {
            $refusal = $this->buildRefusalResponse($session, $question, $autoFilters);
            $totalTime = (microtime(true) - $start) * 1000;
            Log::channel(config('rag.logging.channel', 'rag'))->info('RAG pipeline (stream): refusal (no chunks)', [
                'session_id' => $session->id,
                'question_length' => mb_strlen($question),
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

        $confidence = $this->assessConfidence($chunks);
        $hasOldDocs = $this->hasOldDocuments($chunks);
        $systemPrompt = $this->buildSystemPrompt($confidence, $hasOldDocs);
        $context = $this->reorderForLostInTheMiddle($chunks);

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
        $scope = $this->buildFilterNote($autoFilters);
        if ($scope !== '') {
            array_unshift($context, (object) [
                'document_title' => 'Search Scope',
                'content' => $scope,
                'page_number' => null,
            ]);
        }

        $sources = $this->buildSources($chunks);
        yield json_encode(['type' => 'sources', 'sources' => $sources]);

        yield json_encode([
            'type' => 'status',
            'stage' => 'generating',
            'message' => $isBurmese ? 'အဖြေထုတ်လုပ်နေသည်...' : 'Generating answer...',
        ]);

        $fullContent = '';
        $t0 = microtime(true);
        $stream = $llm->completeStream($systemPrompt, $llmQuestion, $context, [
            'temperature' => 0.3,
            'max_tokens' => $this->maxTokens,
        ]);

        foreach ($stream as $chunk) {
            $fullContent .= $chunk;
            yield json_encode(['type' => 'chunk', 'content' => $chunk]);
        }
        $llmTime = (microtime(true) - $t0) * 1000;

        $this->saveAssistantMessage($session, $fullContent, $sources);

        $totalTime = (microtime(true) - $start) * 1000;
        Log::channel(config('rag.logging.channel', 'rag'))->info('RAG pipeline (stream): complete', [
            'session_id' => $session->id,
            'question_length' => mb_strlen($question),
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
     * @return array{user_ids?: array, project?: string, report_date_from?: string, report_date_to?: string}
     *                                                                                                       Extracted filters keyed by type. Example: ["user_ids" => ["01J..."], "project" => "Orion", "report_date_from" => "2026-04-01", "report_date_to" => "2026-04-30"]
     */
    private function extractFiltersFromQuestion(string $question): array
    {
        $filters = [];

        // ── User name extraction ──────────────────────────────────────────
        // Look up user names from the DB; match against the question
        try {
            $users = $this->cache->remember('rag:users', 300, fn (): Collection => User::select('id', 'name')->get());
            $matchedUserIds = [];
            foreach ($users as $user) {
                $cleanName = preg_quote($user->name, '/');
                if (preg_match('/'.$cleanName.'/iu', $question)) {
                    $matchedUserIds[] = $user->id;

                    continue;
                }
                // Try matching just the base name before any English parenthetical
                $baseName = preg_replace('/\s*\([^)]*\)$/u', '', $user->name);
                if ($baseName !== $user->name && preg_match('/'.preg_quote($baseName, '/').'/iu', $question)) {
                    $matchedUserIds[] = $user->id;
                }
            }
            if ($matchedUserIds !== []) {
                $filters['user_ids'] = $matchedUserIds;
            }
        } catch (\Throwable) {
            // DB not available — skip
        }

        // ── Project name extraction ───────────────────────────────────────
        try {
            $projects = $this->cache->remember('rag:projects', 300, fn (): Collection => Document::distinct()->whereNotNull('project')->where('project', '!=', '')->pluck('project'));

            // Sort projects by length descending to match the most specific project name first
            $sortedProjects = $projects->sortByDesc(fn ($p) => mb_strlen($p));

            foreach ($sortedProjects as $project) {
                $clean = preg_quote($project, '/');
                if (preg_match('/'.$clean.'/iu', $question)) {
                    $filters['project'] = $project;
                    break;
                }
            }
        } catch (\Throwable) {
            // DB not available — skip
        }

        // ── Time period extraction (Myanmar + English) ────────────────────
        $now = now();
        $today = $now->copy()->startOfDay();
        $timePeriods = [

            // Myanmar: today
            ['/ဒီနေ့/u',          'today'],
            ['/ယနေ့/u',            'today'],
            ['/မနေ့/u',            'yesterday'],
            ['/မနေ့က/u',           'yesterday'],

            // Myanmar: this week/month/year
            ['/ဒီတစ်ပတ်/u',        'this_week'],
            ['/ဒီတစ်လ/u',          'this_month'],
            ['/ဒီလ/u',              'this_month'],
            ['/ဒီတစ်နှစ်/u',       'this_year'],

            // Myanmar: last week/month/year (ပြီးခဲ့တဲ့ = last/past)
            ['/ပြီးခဲ့တဲ့\s*အပတ်/u',  'last_week'],
            ['/ပြီးခဲ့တဲ့\s*လ/u',     'last_month'],
            ['/ပြီးခဲ့တဲ့\s*နှစ်/u',  'last_year'],

            // English
            ['/\bthis\s*day\b/i',    'today'],
            ['/\btoday\b/i',         'today'],
            ['/\byesterday\b/i',     'yesterday'],
            ['/\blast\s*night\b/i',  'yesterday'],
            ['/\bthis\s*week\b/i',   'this_week'],
            ['/\bthis\s*month\b/i',  'this_month'],
            ['/\bthis\s*year\b/i',   'this_year'],
        ];

        foreach ($timePeriods as [$pattern, $period]) {
            if (preg_match($pattern, $question)) {
                $filters = $this->applyTimePeriod($filters, $period, $today);
                break;
            }
        }

        // ── Burmese month names + digit conversion ──────────────────────
        $burmeseDigitMap = ['၀' => '0', '၁' => '1', '၂' => '2', '၃' => '3', '၄' => '4', '၅' => '5', '၆' => '6', '၇' => '7', '၈' => '8', '၉' => '9'];
        $hasBurmeseDigits = strtr($question, $burmeseDigitMap) !== $question;
        // Normalized copy with digits converted (for number matching)
        $qDigits = strtr($question, $burmeseDigitMap);

        $burmeseMonths = [
            'ဇန်နဝါရီ' => 1, 'ဖေဖော်ဝါရီ' => 2, 'မတ်' => 3, 'ဧပြီ' => 4,
            'မေ' => 5, 'ဇွန်' => 6, 'ဇူလိုင်' => 7, 'ဩဂုတ်' => 8,
            'စက်တင်ဘာ' => 9, 'အောက်တိုဘာ' => 10, 'နိုဝင်ဘာ' => 11, 'ဒီဇင်ဘာ' => 12,
        ];

        foreach ($burmeseMonths as $mmName => $mmNum) {
            $monthPtn = preg_quote($mmName, '/');
            $mmPad = str_pad((string) $mmNum, 2, '0', STR_PAD_LEFT);

            // "၂၀၂၆ ခုနှစ် ဧပြီလ" or "2026 ခုနှစ် ဧပြီလ"
            if (preg_match('/(\d{4})\s*ခုနှစ်\s*'.$monthPtn.'လ/u', $qDigits, $m)) {
                $filters['report_date_from'] = "{$m[1]}-{$mmPad}-01";
                $filters['report_date_to'] = "{$m[1]}-{$mmPad}-".now()->create((int) $m[1], $mmNum)->endOfMonth()->format('d');
                break;
            }
            // "ဧပြီလ ၂၀၂၆" or "ဧပြီလ 2026"
            if (preg_match('/'.$monthPtn.'လ\s*(\d{4})/u', $qDigits, $m)) {
                $filters['report_date_from'] = "{$m[1]}-{$mmPad}-01";
                $filters['report_date_to'] = "{$m[1]}-{$mmPad}-".now()->create((int) $m[1], $mmNum)->endOfMonth()->format('d');
                break;
            }
            // "ဧပြီလ" alone → current year
            if (preg_match('/'.$monthPtn.'လ/u', $question)) {
                $year = now()->year;
                $filters['report_date_from'] = "{$year}-{$mmPad}-01";
                $filters['report_date_to'] = "{$year}-{$mmPad}-".now()->create($year, $mmNum)->endOfMonth()->format('d');
                break;
            }
        }

        // ── Date patterns (most specific first) ──────────────────────────
        // Use digit-normalized question for number patterns so Burmese
        // digits like "၂၀၂၆-၀၄" also match as "2026-04".
        $dateSrc = $hasBurmeseDigits ? $qDigits : $question;

        // YYYY-MM-DD (e.g. 2026-04-07)
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $dateSrc, $m)) {
            $filters['report_date_from'] = "{$m[1]}-{$m[2]}-{$m[3]}";
            $filters['report_date_to'] = "{$m[1]}-{$m[2]}-{$m[3]}";
        } elseif (preg_match('/\b(\d{4})-(\d{2})\b/', $question, $m)) {
            // YYYY-MM (e.g. 2026-04)
            $filters['report_date_from'] = "{$m[1]}-{$m[2]}-01";
            $filters['report_date_to'] = "{$m[1]}-{$m[2]}-".now()->create($m[1], $m[2])->endOfMonth()->format('d');
        } elseif (preg_match('/\b(\d{4})-(January|February|March|April|May|June|July|August|September|October|November|December)\b/i', $question, $m)) {
            // YYYY-MonthName (e.g. 2026-April) — may come from normalizeQuestion()
            $monthMap = [
                'January' => '01', 'February' => '02', 'March' => '03',
                'April' => '04', 'May' => '05', 'June' => '06',
                'July' => '07', 'August' => '08', 'September' => '09',
                'October' => '10', 'November' => '11', 'December' => '12',
            ];
            $monthNum = $monthMap[$m[2]];
            $filters['report_date_from'] = "{$m[1]}-{$monthNum}-01";
            $filters['report_date_to'] = "{$m[1]}-{$monthNum}-".now()->create((int) $m[1], (int) $monthNum)->endOfMonth()->format('d');
        } elseif (preg_match('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})\b/i', $question, $m)) {
            // MonthName DD (e.g. "April 15") — no year, use current year
            $monthMap = [
                'January' => '01', 'February' => '02', 'March' => '03',
                'April' => '04', 'May' => '05', 'June' => '06',
                'July' => '07', 'August' => '08', 'September' => '09',
                'October' => '10', 'November' => '11', 'December' => '12',
            ];
            $monthNum = $monthMap[$m[1]];
            $day = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $year = now()->year;
            $filters['report_date_from'] = "{$year}-{$monthNum}-{$day}";
            $filters['report_date_to'] = "{$year}-{$monthNum}-{$day}";
        } else {
            // Quarter / year / month-name patterns
            $datePatterns = [
                '/\b(Q[1-4]\s*\d{4})\b/i',
                '/\b(\d{4})\b/',
                '/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4}\b/i',
            ];

            foreach ($datePatterns as $pattern) {
                if (preg_match($pattern, $question, $matches)) {
                    $dateStr = $matches[1];
                    if (preg_match('/^Q[1-4]/i', $dateStr)) {
                        $quarter = (int) substr($dateStr, 1, 1);
                        $year = (int) trim(substr($dateStr, strpos($dateStr, ' ') + 1));
                        $filters['report_date_from'] = "{$year}-".str_pad((string) (($quarter - 1) * 3 + 1), 2, '0', STR_PAD_LEFT).'-01';
                        $filters['report_date_to'] = "{$year}-".str_pad((string) ($quarter * 3), 2, '0', STR_PAD_LEFT).'-31';
                    } elseif (preg_match('/^\d{4}$/', $dateStr)) {
                        // Use digit-normalized $dateSrc so Burmese
                        // digits like "၂၀၂၆" → "2026"
                        preg_match('/\b(\d{4})\b/', $dateSrc, $m2);
                        $year = $m2[1] ?? $dateStr;
                        $filters['report_date_from'] = "{$year}-01-01";
                        $filters['report_date_to'] = "{$year}-12-31";
                    }
                    break;
                }
            }
        }

        return $filters;
    }

    /**
     * Refine a question string into a clean FTS query
     *
     * Strips user names, project names, date/time patterns, stopwords
     * (English and Burmese conversational words), and short tokens from
     * the question, returning a space-separated string suitable for
     * PostgreSQL's plainto_tsquery. Handles Burmese Unicode by not using
     * \b word boundaries. Falls back to a minimal query ("report") if
     * all content words are stripped.
     *
     * @param  string  $question  The search question (may already be alias-expanded). Example: "Show me John's report for April"
     * @param  array  $filters  Extracted filters (used to strip user/project names). Example: ["user_ids" => ["01J..."], "report_date_from" => "2026-04-01"]
     * @return string Cleaned query string for plainto_tsquery. Example: "report April"
     */
    private function refineFtsQuery(string $question, array $filters): string
    {
        static $stopwords = [
            'show', 'list', 'tell', 'give', 'find', 'does', 'have', 'has',
            'from', 'this', 'that', 'what', 'when', 'where', 'which', 'who',
            'will', 'would', 'could', 'should', 'can', 'need', 'want',
            'there', 'their', 'with', 'about', 'into', 'over', 'than',
            'then', 'also', 'just', 'very', 'been', 'were', 'been', 'being',
            'more', 'some', 'any', 'each', 'every', 'both', 'most',
            'first', 'last', 'next', 'other', 'after', 'before', 'between',
            'your', 'yours', 'ours', 'theirs', 'mine', 'his', 'hers', 'its',
            'make', 'made', 'done', 'get', 'got', 'see', 'saw', 'use', 'used',
            'like', 'good', 'well', 'way', 'know', 'take', 'think',
            'yesterday', 'today', 'tomorrow',
            'report', 'reports', 'reported', 'reporting',
            // Burmese/Myanmar conversational framing words
            'ရှိ', 'ရှိလား', 'ရှိပါ', 'ရှိပါတယ်', 'ပါ', 'ပါတယ်',
            'တယ်', 'သည်', 'ကို', 'မှာ', 'မှ', 'တွင်', 'အတွက်',
            'အား', 'ဖြင့်', 'နှင့်', 'အကြောင်း', 'ကြောင်း',
            'လား', 'ဟုတ်', 'မဟုတ်', 'ဖူး', 'ဘူး',
            'ဒီနေ့', 'ယနေ့', 'မနေ့', 'မနေ့က',
            'တင်ထားတဲ့', 'တင်သွင်းတဲ့', 'တင်ပြထားတဲ့',
            'ရေးသားတဲ့', 'ရေးထားတဲ့', 'ပြုလုပ်တဲ့',
            'ဆိုင်ရာ', 'ပတ်သက်', 'ပေးပါ', 'ပေးပါဦး',
            'ဆိုရင်', 'ဆိုရင်လည်း', 'ဆိုတာ', 'ဆိုတာလဲ',
            'ဘယ်', 'ဘယ်လို', 'ဘယ်ရက်', 'ဘယ်အချိန်',
            'ရှိတယ်ဆို', 'ရှိသလား', 'ရှိခဲ့လား', 'ရှိနေလား',
            'ဘာတွေ', 'ဘာလဲ', 'လဲ', 'လဲဆိုတာ',
            'အဲ့ဒါဆို', 'ဒါဆို', 'အဲ့ဒီ', 'အဲဒီ', 'ရှိတဲ့', 'ဆို', 'ရက်',
        ];

        // Strip matched user names
        if (isset($filters['user_ids'])) {
            try {
                $users = User::whereIn('id', $filters['user_ids'])->pluck('name');
                foreach ($users as $name) {
                    // Use a more robust pattern for Burmese names (not relying on \b)
                    $question = preg_replace('/'.preg_quote($name, '/').'/iu', '', $question);
                    // Also try stripping just the base name before English parenthetical
                    $baseName = preg_replace('/\s*\([^)]*\)$/u', '', $name);
                    if ($baseName !== $name && $baseName !== '') {
                        $question = preg_replace('/'.preg_quote($baseName, '/').'/iu', '', $question);
                    }
                }
            } catch (\Throwable) {
                // skip
            }
        }

        // Strip matched project name
        if (isset($filters['project'])) {
            $question = preg_replace('/'.preg_quote($filters['project'], '/').'/iu', '', $question);
        }

        // Strip date patterns (YYYY-MM-DD or YYYY-MM) before individual
        // year components to avoid leaving bare "-MM" tokens that PostgreSQL
        // plainto_tsquery would interpret as a negation operator.
        $question = preg_replace('/\b\d{4}[-\.\/]\d{1,2}([-\.\/]\d{1,2})?\b/', '', $question);

        // Strip YYYY-MonthName patterns (e.g. "2026-April") before individual
        // year/month stripping so they don't leave a bare "-" token.
        $question = preg_replace('/\b\d{4}[-\.\/](January|February|March|April|May|June|July|August|September|October|November|December)\b/i', '', $question);

        // Strip MonthName DD patterns (e.g. "April 15") before individual
        // month stripping so they don't leave a bare day number.
        $question = preg_replace('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2}\b/i', '', $question);

        // Strip year and quarter patterns
        $question = preg_replace('/\b\d{4}\b/', '', $question);
        $question = preg_replace('/\bQ[1-4]\b/i', '', $question);

        // Strip month names
        $question = preg_replace('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\b/i', '', $question);

        // Strip common stopwords
        foreach ($stopwords as $word) {
            $quoted = preg_quote($word, '/');
            // If it's a Burmese word (contains Burmese characters), don't use \b
            if (preg_match('/[\x{1000}-\x{109F}]/u', $word)) {
                $question = preg_replace('/'.$quoted.'/iu', '', $question);
            } else {
                $question = preg_replace('/\b'.$quoted.'\b/iu', '', $question);
            }
        }

        // Keep meaningful content words:
        // - ASCII words: must be > 2 chars, not just punctuation
        // - Non-ASCII words (Burmese, etc.): must be > 1 char, not in
        //   stopwords list. The pg 'english' FTS parser keeps non-ASCII
        //   tokens as-is so they can match document content.
        $words = preg_split('/\s+/', $question);
        $words = array_filter($words, function (string $w) use ($stopwords): bool {
            $w = trim($w);
            if ($w === '') {
                return false;
            }

            // Check if it's a stopword (for cases where preg_replace missed it)
            if (in_array(mb_strtolower($w), $stopwords)) {
                return false;
            }

            if (preg_match('/[a-zA-Z0-9]/', $w)) {
                return mb_strlen($w) > 2;
            }

            return mb_strlen($w) > 1;
        });
        $words = array_map(fn (string $w): string => preg_replace('/[^a-zA-Z0-9\p{L}\-\.]/u', '', $w), $words);
        $words = array_filter($words, fn (string $w): bool => $w !== '');
        // Clean up tokens: strip leading/trailing hyphens, and remove
        // tokens that are purely hyphens or hyphen-prefixed numbers
        // (leftover date fragments like "-04" that FTS would treat as NOT)
        $words = array_filter($words, fn (string $w): bool => ! preg_match('/^-+\d*$/', $w));
        $words = array_map(fn (string $w): string => trim($w, '-'), $words);
        $words = array_filter($words, fn (string $w): bool => $w !== '');
        $words = array_udiff($words, $stopwords, fn (string $a, string $b): int => mb_strtolower($a) <=> mb_strtolower($b));

        $result = implode(' ', array_unique($words));
        $result = trim(preg_replace('/\s+/', ' ', $result));

        if ($result === '') {
            // Fallback: use non-stopword tokens from original question
            $fallback = preg_replace('/[^\p{L}0-9\s\-\.]/u', '', $question, -1);
            $fallback = preg_replace('/\b\d{4}[-\.\/]\d{1,2}([-\.\/]\d{1,2})?\b/', '', $fallback, -1);
            $fallback = preg_replace('/\b\d{4}\b/', '', $fallback, -1);
            $fallback = preg_replace('/-+/', ' ', $fallback, -1);
            $fallback = trim(preg_replace('/\s+/', ' ', $fallback, -1));

            return $fallback !== '' ? $fallback : 'report';
        }

        return $result;
    }

    /**
     * Apply a named time period to the filter array
     *
     * Converts shorthand period names (today, yesterday, this_week,
     * last_week, this_month, last_month, this_year, last_year) to
     * concrete report_date_from/report_date_to date ranges using
     * the provided reference date (today).
     *
     * @param  array  $filters  Current filter array (may be empty). Example: []
     * @param  string  $period  Named time period. Example: "this_month"
     * @param  Carbon  $today  Reference date for relative calculations. Example: now()->startOfDay()
     * @return array Updated filters with report_date_from and report_date_to set.
     *               Example: ["report_date_from" => "2026-05-01", "report_date_to" => "2026-05-31"]
     */
    private function applyTimePeriod(array $filters, string $period, Carbon $today): array
    {
        $from = null;
        $to = null;

        switch ($period) {
            case 'today':
                $from = $today->copy();
                $to = $today->copy()->endOfDay();
                break;
            case 'yesterday':
                $from = $today->copy()->subDay();
                $to = $from->copy()->endOfDay();
                break;
            case 'this_week':
                $from = $today->copy()->startOfWeek(Carbon::MONDAY);
                $to = $from->copy()->endOfWeek(Carbon::SUNDAY);
                break;
            case 'last_week':
                $from = $today->copy()->subWeek()->startOfWeek(Carbon::MONDAY);
                $to = $from->copy()->endOfWeek(Carbon::SUNDAY);
                break;
            case 'this_month':
                $from = $today->copy()->startOfMonth();
                $to = $today->copy()->endOfMonth();
                break;
            case 'last_month':
                $from = $today->copy()->subMonth()->startOfMonth();
                $to = $from->copy()->endOfMonth();
                break;
            case 'this_year':
                $from = $today->copy()->startOfYear();
                $to = $today->copy()->endOfYear();
                break;
            case 'last_year':
                $from = $today->copy()->subYear()->startOfYear();
                $to = $from->copy()->endOfYear();
                break;
        }

        if ($from !== null) {
            $filters['report_date_from'] = $from->format('Y-m-d');
            $filters['report_date_to'] = $to->format('Y-m-d');
        }

        return $filters;
    }

    /**
     * Replace relative time references with literal dates
     *
     * Substitutes relative time expressions (ဒီနေ့/today, မနေ့/yesterday,
     * ဒီတစ်လ/this month, ပြီးခဲ့တဲ့အပတ်/last week, etc.) in both Burmese
     * and English with their literal date ranges (e.g. "2026-05-17" or
     * "2026-05-11 to 2026-05-17"). This prevents the LLM from having to
     * guess the current date when answering time-sensitive questions.
     *
     * @param  string  $question  The question text potentially containing relative time references.
     *                            Example: "What reports were filed yesterday?"
     * @return string Question with relative times replaced by literal dates.
     *                Example: "What reports were filed on 2026-05-16?"
     */
    private function resolveTimeReferences(string $question): string
    {
        $now = now();
        $today = $now->copy()->startOfDay();

        $dateRanges = [

            // Myanmar: today
            ['/ဒီနေ့/u',     $today->format('Y-m-d')],
            ['/ယနေ့/u',       $today->format('Y-m-d')],

            // Myanmar: yesterday
            ['/မနေ့/u',       $today->copy()->subDay()->format('Y-m-d')],

            // Myanmar: this week/month/year
            ['/ဒီတစ်ပတ်/u',   $today->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d').' to '.$today->copy()->endOfWeek(Carbon::SUNDAY)->format('Y-m-d')],
            ['/ဒီတစ်လ/u',     $today->copy()->startOfMonth()->format('Y-m-d').' to '.$today->copy()->endOfMonth()->format('Y-m-d')],
            ['/ဒီလ/u',         $today->copy()->startOfMonth()->format('Y-m-d').' to '.$today->copy()->endOfMonth()->format('Y-m-d')],
            ['/ဒီတစ်နှစ်/u',  $today->copy()->startOfYear()->format('Y-m-d').' to '.$today->copy()->endOfYear()->format('Y-m-d')],

            // Myanmar: last week/month/year
            ['/ပြီးခဲ့တဲ့\s*အပတ်/u', $today->copy()->subWeek()->startOfWeek(Carbon::MONDAY)->format('Y-m-d').' to '.$today->copy()->subWeek()->endOfWeek(Carbon::SUNDAY)->format('Y-m-d')],
            ['/ပြီးခဲ့တဲ့\s*လ/u',    $today->copy()->subMonth()->startOfMonth()->format('Y-m-d').' to '.$today->copy()->subMonth()->endOfMonth()->format('Y-m-d')],
            ['/ပြီးခဲ့တဲ့\s*နှစ်/u', $today->copy()->subYear()->startOfYear()->format('Y-m-d').' to '.$today->copy()->subYear()->endOfYear()->format('Y-m-d')],

            // English: today / yesterday
            ['/\btoday\b/i',      $today->format('Y-m-d')],
            ['/\byesterday\b/i',  $today->copy()->subDay()->format('Y-m-d')],

            // English: this week/month/year
            ['/\bthis\s*week\b/i',  $today->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d').' to '.$today->copy()->endOfWeek(Carbon::SUNDAY)->format('Y-m-d')],
            ['/\bthis\s*month\b/i', $today->copy()->startOfMonth()->format('Y-m-d').' to '.$today->copy()->endOfMonth()->format('Y-m-d')],
            ['/\bthis\s*year\b/i',  $today->copy()->startOfYear()->format('Y-m-d').' to '.$today->copy()->endOfYear()->format('Y-m-d')],
        ];

        foreach ($dateRanges as [$pattern, $replacement]) {
            $question = preg_replace($pattern, $replacement, $question, -1);
        }

        return $question;
    }

    /**
     * Expand a search query into multiple reformulations
     *
     * When query expansion is enabled, asks the LLM to generate
     * numExpansionQueries alternative reformulations of the question
     * to improve document retrieval recall. The original question is
     * prepended as the first query. If the LLM call fails, falls back
     * to returning only the original question.
     *
     * @param  string  $question  The original search question. Example: "Q3 revenue report"
     * @param  LLMServiceInterface  $llm  The LLM service to generate reformulations. Example: $this->llm
     * @return array<string> Array of query strings, with the original first.
     *                       Example: ["Q3 revenue report", "third quarter revenue", "Q3 financial results"]
     */
    private function expandQuery(string $question, LLMServiceInterface $llm): array
    {
        if (! $this->queryExpansionEnabled) {
            return [$question];
        }

        try {
            $prompt = "You are a search query optimizer. Generate {$this->numExpansionQueries} different reformulations of the given question to improve document retrieval. Return ONE reformulation per line, no numbering, no extra text.\n\nQuestion: {$question}";

            $response = $llm->complete(
                'You generate search queries. Return only the queries, one per line.',
                $prompt,
                [],
                ['temperature' => 0.3, 'max_tokens' => 500],
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
     * @return array Reordered chunks with best first, worst last.
     *               Example: [best, mid1, worst, mid2] for 4 chunks
     */
    private function reorderForLostInTheMiddle(array $chunks): array
    {
        if (count($chunks) <= 2) {
            return $chunks;
        }

        $remaining = array_slice($chunks, 1);
        $middle = (int) ceil(count($remaining) / 2);

        $ordered = array_merge(
            [$chunks[0]],
            array_slice($remaining, 0, $middle - 1),
            [$chunks[count($chunks) - 1]],
            array_slice($remaining, $middle - 1),
        );

        return $ordered;
    }

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
     *
     * @param  array  $chunks  Sorted chunks with similarity_score property.
     *                         Example: [['similarity_score' => 0.92, 'content' => '...'], ['similarity_score' => 0.45, 'content' => '...']]
     * @param  array  $filters  Current filters that may lower the base threshold.
     *                          Example: ["user_ids" => ["01J..."]]
     * @return array Filtered chunks above the determined threshold.
     *               Example: [['similarity_score' => 0.92, 'content' => '...']]
     */
    private function applyDynamicThreshold(array $chunks, array $filters = []): array
    {
        if ($chunks === []) {
            return [];
        }

        $scores = array_map(fn (object $c): float => (float) $c->similarity_score, $chunks);
        rsort($scores);

        if (count($scores) <= 1) {
            return array_slice($chunks, 0, $this->topK);
        }

        $maxGap = 0.0;
        $gapIndex = 0;
        for ($i = 0; $i < count($scores) - 1; $i++) {
            $gap = $scores[$i] - $scores[$i + 1];
            if ($gap > $maxGap) {
                $maxGap = $gap;
                $gapIndex = $i;
            }
        }

        $baseThreshold = $this->similarityThreshold;
        if (! empty($filters['user_ids']) || ! empty($filters['project']) || ! empty($filters['report_date_from'])) {
            $baseThreshold = 0.45;
        }

        $cutoff = $baseThreshold;
        if ($maxGap > 0.15) {
            $cutoff = max($cutoff, $scores[$gapIndex + 1]);
        } else {
            $scaled = (float) $scores[0] * 0.85;
            $cutoff = max($cutoff, $scaled);
        }

        $filtered = array_values(
            array_filter($chunks, fn (object $c): bool => (float) $c->similarity_score >= $cutoff)
        );

        $filtered = array_slice($filtered, 0, $this->topK);

        if ($filtered === [] && $chunks !== [] && $scores[0] >= 0.25) {
            return array_slice($chunks, 0, min(1, $this->topK));
        }

        return $filtered;
    }

    /**
     * Apply Maximal Marginal Relevance (MMR) reranking
     *
     * Selects a diverse subset of chunks by balancing relevance (similarity_score)
     * against redundancy (same-document penalty). The first chunk is always the
     * highest-scoring; subsequent selections maximise mmr_lambda * relevance -
     * (1 - mmr_lambda) * max_similarity_to_selected. When two chunks share the
     * same document_id, the redundancy penalty is 1.0 (fully penalised).
     * Returns early (unchanged) when MMR is disabled or there is <= 1 chunk.
     *
     * @param  array  $chunks  Candidate chunks with document_id and similarity_score.
     *                         Example: [['document_id' => '01J...', 'similarity_score' => 0.9, 'content' => '...']]
     * @return array MMR-diversified chunks capped at topK.
     *               Example: [best_chunk, diverse_chunk_from_other_doc, ...]
     */
    private function applyMMR(array $chunks): array
    {
        if (! $this->mmrEnabled || count($chunks) <= 1) {
            return $chunks;
        }

        $topK = min($this->topK, count($chunks));
        $selected = [];
        $candidates = $chunks;

        $selected[] = array_shift($candidates);

        while (count($selected) < $topK && $candidates !== []) {
            $bestIdx = -1;
            $bestScore = -INF;

            foreach ($candidates as $i => $cand) {
                $simScore = (float) $cand->similarity_score;
                $maxSimToSelected = 0.0;

                foreach ($selected as $sel) {
                    $penalty = $cand->document_id === $sel->document_id ? 1.0 : 0.0;
                    if ($penalty > $maxSimToSelected) {
                        $maxSimToSelected = $penalty;
                    }
                }

                $mmr = $this->mmrLambda * $simScore - (1.0 - $this->mmrLambda) * $maxSimToSelected;

                if ($mmr > $bestScore) {
                    $bestScore = $mmr;
                    $bestIdx = $i;
                }
            }

            if ($bestIdx !== -1) {
                $selected[] = $candidates[$bestIdx];
                array_splice($candidates, $bestIdx, 1);
            } else {
                break;
            }
        }

        return $selected;
    }

    /**
     * Build a human-readable filter description for the LLM context
     *
     * Converts the internal filters array into a text note listing
     * users (by name), project, and date/date range that was applied
     * during the search. This is prepended to the LLM context so the
     * model knows the exact scope used (e.g. a specific date rather
     * than the relative term "yesterday"). Returns empty string when
     * no filters are active.
     *
     * @param  array  $filters  The active search filters.
     *                          Example: ["user_ids" => ["01J..."], "project" => "Orion", "report_date_from" => "2026-04-01", "report_date_to" => "2026-04-30"]
     * @return string Formatted filter description, or empty string.
     *                Example: "Search scope:\n- Users: John Doe\n- Project: Orion\n- Date range: 2026-04-01 to 2026-04-30"
     */
    private function buildFilterNote(array $filters): string
    {
        $lines = [];

        if (! empty($filters['user_ids'])) {
            $names = User::whereIn('id', (array) $filters['user_ids'])->pluck('name')->toArray();
            $lines[] = '- Users: '.implode(', ', $names);
        }

        if (! empty($filters['project'])) {
            $lines[] = '- Project: '.$filters['project'];
        }

        $from = $filters['report_date_from'] ?? null;
        $to = $filters['report_date_to'] ?? null;
        if ($from !== null && $to !== null) {
            $lines[] = $from === $to
                ? '- Date: '.$from
                : "- Date range: {$from} to {$to}";
        } elseif ($from !== null) {
            $lines[] = "- From: {$from}";
        } elseif ($to !== null) {
            $lines[] = "- Until: {$to}";
        }

        if ($lines === []) {
            return '';
        }

        return "Search scope:\n".implode("\n", $lines);
    }

    /**
     * Normalise a question string
     *
     * Trims whitespace, validates non-empty, truncates to maxQuestionLength,
     * and converts bare YYYY-MM patterns (without a following day) to
     * YYYY-MonthName format so the LLM and date parser can recognise them
     * as month references rather than generic numbers.
     *
     * @param  string  $question  Raw question text. Example: "  What is revenue for 2026-04?  "
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
     * @return ChatSession The resolved or newly created session.
     *                     Example: ChatSession instance with id, title, etc.
     *
     * @throws \RuntimeException When session not found, trashed, or expired (>24h inactive)
     *                           Example: resolveSession('nonexistent') → RuntimeException("Chat session not found.")
     *                           Example: resolveSession('expired-session') → RuntimeException("Chat session has expired.")
     */
    private function resolveSession(?string $sessionId, ?string $userId = null): ChatSession
    {
        if ($sessionId !== null) {
            $query = ChatSession::where('id', $sessionId);

            if ($userId !== null) {
                $query->where('user_id', $userId);
            }

            $session = $query->first();
            if ($session === null) {
                throw new \RuntimeException('Chat session not found.');
            }
            if ($session->trashed()) {
                throw new \RuntimeException('Chat session has been deleted.');
            }
            if ($session->last_activity_at < now()->subHours(24)) {
                throw new \RuntimeException('Chat session has expired. Please start a new chat.');
            }
            $session->update(['last_activity_at' => now()]);

            return $session;
        }

        return ChatSession::create([
            'title' => 'New Chat',
            'last_activity_at' => now(),
            'user_id' => $userId,
        ]);
    }

    /**
     * Check whether the session has reached its message limit
     *
     * Counts the current messages in the session and throws if the count
     * meets or exceeds maxMessagesPerSession. This prevents runaway sessions
     * that could consume excessive LLM context window.
     *
     * @param  ChatSession  $session  The chat session to check. Example: $chatSession
     *
     * @throws \RuntimeException When message count >= maxMessagesPerSession
     *                           Example: checkMessageLimit($fullSession) → RuntimeException("Session message limit reached.")
     */
    private function checkMessageLimit(ChatSession $session): void
    {
        $count = $session->messages()->count();
        if ($count >= $this->maxMessagesPerSession) {
            throw new \RuntimeException('Session message limit reached. Please start a new chat.');
        }
    }

    /**
     * Save a user message to the session
     *
     * Updates the session's last_activity_at, increments message_count,
     * and creates a new ChatMessage with role "user" and the question content.
     *
     * @param  ChatSession  $session  The chat session to save to. Example: $resolvedSession
     * @param  string  $question  The user's question text. Example: "What is Q3 revenue?"
     * @return ChatMessage The newly created message instance.
     *                     Example: ChatMessage instance with role="user", content="What is Q3 revenue?"
     */
    private function saveUserMessage(ChatSession $session, string $question): ChatMessage
    {
        $session->update(['last_activity_at' => now()]);
        $session->increment('message_count');

        return ChatMessage::create([
            'session_id' => $session->id,
            'role' => 'user',
            'content' => $question,
        ]);
    }

    /**
     * Save an assistant message to the session
     *
     * If the session title is still "New Chat", derives the title from the
     * first 50 characters of the assistant response. Updates last_activity_at,
     * increments message_count, and creates a new ChatMessage with role
     * "assistant", the LLM response content, and source citations.
     *
     * @param  ChatSession  $session  The chat session to save to. Example: $resolvedSession
     * @param  string  $content  The LLM-generated response text. Example: "Revenue for Q3 was $45.2 million..."
     * @param  array  $sources  Array of source document citations. Example: [["document_id" => "01J...", "document_title" => "Q3 Report.pdf", "similarity_score" => 0.89]]
     * @return ChatMessage The newly created message instance.
     *                     Example: ChatMessage instance with role="assistant", content="Revenue...", sources=[...]
     */
    private function saveAssistantMessage(ChatSession $session, string $content, array $sources): ChatMessage
    {
        if ($session->title === 'New Chat') {
            $session->update([
                'title' => mb_substr($content, 0, 50),
            ]);
        }
        $session->update(['last_activity_at' => now()]);
        $session->increment('message_count');

        return ChatMessage::create([
            'session_id' => $session->id,
            'role' => 'assistant',
            'content' => $content,
            'sources' => $sources,
        ]);
    }

    /**
     * Build a refusal response when no relevant chunks are found
     *
     * Generates a polite refusal message in Burmese or English (detected from
     * question content). When date filters are present, attempts to find the
     * nearest available document date and provides a helpful hint. Also checks
     * whether the nearest document has vector embeddings to explain whether
     * it is indexed or not. Saves the refusal as an assistant message with
     * empty sources.
     *
     * @param  ChatSession  $session  The chat session. Example: $resolvedSession
     * @param  string  $question  The original question for language detection. Example: "What is the revenue?"
     * @param  array  $filters  Applied filters for nearest-date hint logic. Example: ["report_date_from" => "2026-04-01", "report_date_to" => "2026-04-30"]
     * @return array{session_id: string, message: array} Refusal response in standard format.
     *                                                   Example: ["session_id" => "01J...", "message" => ["id" => "01J...", "role" => "assistant", "content" => "I cannot answer...", "sources" => []]]
     */
    private function buildRefusalResponse(ChatSession $session, string $question = '', array $filters = []): array
    {
        $isBurmese = preg_match('/[\x{1000}-\x{109F}]/u', $question) === 1;

        // When the question had date filters, try to find the nearest
        // available date for a helpful hint.
        $hint = '';
        $from = $filters['report_date_from'] ?? null;
        $to = $filters['report_date_to'] ?? null;
        if ($from !== null || $to !== null) {
            $target = $to ?? $from;
            $query = Document::query()->whereNotNull('report_date');
            if (! empty($filters['user_ids'])) {
                $query->whereIn('user_id', (array) $filters['user_ids']);
            }
            if (! empty($filters['project'])) {
                $query->where('project', $filters['project']);
            }
            $nearest = $query->orderByRaw('ABS(report_date - CAST(? AS DATE))', [$target])->value('report_date');
            $nearestStr = $nearest instanceof Carbon
                ? $nearest->toDateString()
                : (string) $nearest;

            if ($nearest !== null) {
                // Check if the document actually has vectors
                $targetDoc = (clone $query)->where('report_date', $nearest)->first();
                $hasVectors = false;
                if ($targetDoc) {
                    $hasVectors = DB::table('vector_embeddings')
                        ->whereIn('chunk_id', function ($q) use ($targetDoc) {
                            $q->select('id')->from('document_chunks')->where('document_id', $targetDoc->id);
                        })->exists();
                }

                if ($nearestStr === ($from ?? $to)) {
                    if (! $hasVectors) {
                        if ($isBurmese) {
                            $hint = "\n\n{$nearestStr} ရက်စွဲအတွက် အစီရင်ခံစာရှိသော်လည်း ရှာဖွေမှုအတွက် အဆင်သင့်မဖြစ်သေးပါ။ (Re-embed ပြုလုပ်ရန် လိုအပ်နိုင်ပါသည်)";
                        } else {
                            $hint = "\n\nA report for {$nearestStr} exists but is not ready for search. (Re-embedding may be required)";
                        }
                    } else {
                        // Exact date found but no chunks above threshold.
                        // Likely a model mismatch or low similarity.
                        if ($isBurmese) {
                            $hint = "\n\n{$nearestStr} ရက်စွဲအတွက် အစီရင်ခံစာရှိသော်လည်း ရှာဖွေမှုရလဒ်တွင် မတွေ့ပါ။ အချက်အလက်များ မပြည့်စုံခြင်း သို့မဟုတ် ရှာဖွေမှုစံနှုန်းနှင့် မကိုက်ညီခြင်းကြောင့် ဖြစ်နိုင်ပါသည်။";
                        } else {
                            $hint = "\n\nA report for {$nearestStr} exists but was not found in search results. This may be due to incomplete indexing or low similarity.";
                        }
                    }
                } else {
                    if ($isBurmese) {
                        $hint = "\n\nအနီးစပ်ဆုံးရှိသော အစီရင်ခံစာများမှာ {$nearestStr} ရက်စွဲတွင် ရှိပါသည်။";
                        if (! $hasVectors) {
                            $hint .= ' (သို့သော် ရှာဖွေမှုအတွက် အဆင်သင့်မဖြစ်သေးပါ)';
                        }
                    } else {
                        $hint = "\n\nThe closest available reports are dated {$nearestStr}.";
                        if (! $hasVectors) {
                            $hint .= ' (But it is not yet ready for search)';
                        }
                    }
                }
            }
        }

        $content = $isBurmese
            ? 'မေးထားသော မေးခွန်းအတွက် အဖြေရှာမတွေ့ပါ။'.$hint
            : 'I cannot answer this question based on the available documents.'.$hint;

        $message = $this->saveAssistantMessage($session, $content, []);

        return [
            'session_id' => $session->id,
            'message' => [
                'id' => $message->id,
                'role' => 'assistant',
                'content' => $content,
                'sources' => [],
                'created_at' => $message->created_at->toIso8601String(),
            ],
        ];
    }

    /**
     * Assess answer confidence based on chunk count
     *
     * Returns "high" (3+ chunks), "low" (1-2 chunks), or "none" (empty).
     * Used by buildSystemPrompt to adjust the LLM's response tone — low
     * confidence adds an instruction to acknowledge uncertainty.
     *
     * @param  array  $chunks  The filtered/re-ranked chunks to evaluate.
     *                         Example: [['similarity_score' => 0.9, 'content' => '...']]
     * @return string Confidence level: "high", "low", or "none".
     *                Example: "high"
     */
    private function assessConfidence(array $chunks): string
    {
        $aboveThreshold = count($chunks);

        return match (true) {
            $aboveThreshold >= 3 => 'high',
            $aboveThreshold >= 1 => 'low',
            default => 'none',
        };
    }

    /**
     * Build the system prompt for the LLM
     *
     * Constructs a context-only instruction set that tells the LLM to answer
     * strictly from provided documents, cite sources, match the user's
     * language, and use metadata headers (Report by, Project, Date) for
     * attribution. Optionally appends low-confidence guidance and a note
     * about old documents (>1 year) for time-sensitive information.
     *
     * @param  string  $confidence  "high", "low", or "none" from assessConfidence(). Example: "high"
     * @param  bool  $hasOldDocuments  Whether any source document is >1 year old. Example: false
     * @return string The system prompt string.
     *                Example: "You are a precise document-answering assistant. Follow these rules strictly:\n\n1. Answer ONLY..."
     */
    private function buildSystemPrompt(string $confidence, bool $hasOldDocuments = false): string
    {
        $prompt = 'You are a precise document-answering assistant. Follow these rules strictly:

1. Answer ONLY using the provided context. Do NOT use prior knowledge.
2. If the context does not fully answer the question, state what you know and what information is missing.
3. NEVER make up or hallucinate information. If uncertain, say so.
4. Cite sources by document title for every factual claim.
5. Respond in the SAME LANGUAGE as the user\'s question.
6. DOCUMENTS contain metadata headers at the top of each chunk: "Report by: {author_name}", "Project: {project_name}", "Date: {report_date}". Use this metadata to answer questions about WHO wrote the report, WHICH PROJECT it belongs to, and WHEN it was written.
7. When a question asks about a specific user\'s reports, look for "Report by:" in the context. When asked about a project, look for "Project:". When asked about dates, look for "Date:" in the context.';

        if ($confidence === 'low') {
            $prompt .= "\n\n8. The available information may be limited. Acknowledge uncertainty clearly and suggest what additional information would help.";
        }

        if ($hasOldDocuments) {
            $prompt .= "\n\n9. Some source documents are over a year old. Note this when the information may be time-sensitive.";
        }

        return $prompt;
    }

    /**
     * Check whether any source document is over a year old
     *
     * Inspects the document_created_at property of each chunk and returns
     * true if any document is older than one year from the current date.
     * Used by buildSystemPrompt to add a time-sensitivity advisory.
     *
     * @param  array  $chunks  The chunks to inspect, each with optional document_created_at.
     *                         Example: [['document_created_at' => '2025-01-15', ...]]
     * @return bool True if at least one document is over 1 year old.
     *              Example: true
     */
    private function hasOldDocuments(array $chunks): bool
    {
        $oneYearAgo = now()->subYear();

        foreach ($chunks as $chunk) {
            if (! isset($chunk->document_created_at)) {
                continue;
            }

            try {
                if (Carbon::parse($chunk->document_created_at)->lt($oneYearAgo)) {
                    return true;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }

    /**
     * Resolve the LLM service for a specific request
     *
     * If the options contain a valid llm_model_id pointing to an active LLM
     * AiModel, creates a new LLMService with the appropriate provider and
     * context token limit. Otherwise returns the default LLM service that
     * was injected at construction time.
     *
     * @param  array  $options  Request options, possibly containing 'llm_model_id'.
     *                          Example: ["llm_model_id" => "01J..."]
     * @return LLMServiceInterface The resolved LLM service (default or overridden).
     *                             Example: $this->llm or new LLMService(...)
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

    /**
     * Build the sources array for the response
     *
     * Maps chunk objects to the standard source citation format with
     * document_id, document_title, chunk_index, page_number, similarity_score
     * (rounded to 4 decimals), and a 200-character content excerpt.
     *
     * @param  array  $chunks  The chunks used in the answer, each with document_id,
     *                         document_title, chunk_index, page_number, similarity_score, and content.
     *                         Example: [['document_id' => '01J...', 'document_title' => 'Report.pdf', 'similarity_score' => 0.8923, ...]]
     * @return array<int, array{document_id: string, document_title: string, chunk_index: mixed, page_number: mixed, similarity_score: float, excerpt: string}>
     *                                                                                                                                                          Standardised source citation array.
     *                                                                                                                                                          Example: [['document_id' => '01J...', 'document_title' => 'Report.pdf', 'similarity_score' => 0.8923, 'excerpt' => 'Revenue reached...']]
     */
    private function buildSources(array $chunks): array
    {
        return array_map(fn (object $chunk): array => [
            'document_id' => $chunk->document_id,
            'document_title' => $chunk->document_title,
            'chunk_index' => $chunk->chunk_index,
            'page_number' => $chunk->page_number ?? null,
            'similarity_score' => round((float) $chunk->similarity_score, 4),
            'excerpt' => mb_substr((string) $chunk->content, 0, 200),
        ], $chunks);
    }
}
