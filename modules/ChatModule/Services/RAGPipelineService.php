<?php

declare(strict_types=1);

namespace Modules\ChatModule\Services;

use App\Models\User;
use Carbon\Carbon;
use Generator;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\ChatModule\Contracts\RAGPipelineServiceInterface;
use Modules\ChatModule\Models\ChatMessage;
use Modules\ChatModule\Models\ChatSession;
use Modules\DocumentModule\Models\Document;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\EmbeddingModule\Services\ProviderFactory;
use Modules\LLMModule\Contracts\LLMServiceInterface;
use Modules\LLMModule\Services\LLMService;
use Modules\SettingsModule\Models\AiModel;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

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

    private ?string $userId;

    private ProviderFactory $providerFactory;

    private CacheRepository $cache;

    private ?AiModel $activeEmbeddingModel = null;

    private ?AiModel $activeLlmModel = null;

    public function __construct(
        EmbeddingServiceInterface $embedder,
        VectorStoreInterface $vectorStore,
        LLMServiceInterface $llm,
        ProviderFactory $providerFactory,
        CacheRepository $cache,
        int $topK = 5,
        float $similarityThreshold = 0.65,
        int $maxQuestionLength = 1000,
        int $maxMessagesPerSession = 100,
        string $searchMode = 'hybrid',
        bool $queryExpansionEnabled = false,
        int $numExpansionQueries = 3,
        bool $mmrEnabled = true,
        float $mmrLambda = 0.7,
        ?string $userId = null,
        ?string $activeEmbeddingModelId = null,
        ?string $activeLlmModelId = null,
    ) {
        $this->embedder = $embedder;
        $this->vectorStore = $vectorStore;
        $this->llm = $llm;
        $this->providerFactory = $providerFactory;
        $this->cache = $cache;
        $this->topK = $topK;
        $this->similarityThreshold = $similarityThreshold;
        $this->maxQuestionLength = $maxQuestionLength;
        $this->maxMessagesPerSession = $maxMessagesPerSession;
        $this->searchMode = $searchMode;
        $this->queryExpansionEnabled = $queryExpansionEnabled;
        $this->numExpansionQueries = $numExpansionQueries;
        $this->mmrEnabled = $mmrEnabled;
        $this->mmrLambda = $mmrLambda;
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
        }
    }

    public function ask(string $question, array $options = []): array
    {
        set_time_limit(120);
        $start = microtime(true);
        $question = $this->normalizeQuestion($question);
        $session = $this->resolveSession($options['session_id'] ?? null, $options['user_id'] ?? $this->userId);
        $this->checkMessageLimit($session);

        $autoFilters = $this->extractFiltersFromQuestion($question);
        $ftsQuery = $this->refineFtsQuery($question, $autoFilters);

        $this->saveUserMessage($session, $question);

        // Resolve relative time references (yesterday, today, …) to literal
        // dates so the LLM doesn't have to guess the current date.
        $llmQuestion = $this->resolveTimeReferences($question);

        // Inherit filters from the previous exchange when the current
        // question doesn't specify any user or project (e.g. follow-ups).
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
                $autoFilters = array_merge($inheritedFilters, $autoFilters);
            }
        }

        // When the question had no filters of its own, also restrict the
        // search to the same documents cited in the previous answer. This
        // keeps follow-ups like "ဘာတွေတင်ထားလဲ?" on the exact same report.
        if ($inherited) {
            $prevAssistantMsg = ChatMessage::where('session_id', $session->id)
                ->where('role', 'assistant')
                ->orderBy('created_at', 'desc')
                ->first();
            if ($prevAssistantMsg !== null) {
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
        }

        $llm = $this->resolveLLM($options);

        $searchQueries = $this->expandQuery($question, $llm);

        $allChunks = [];
        $t0 = microtime(true);

        foreach ($searchQueries as $q) {
            $questionVector = $this->embedder->embed($q);
            $minThreshold = min($this->similarityThreshold, 0.40);
            $filters = array_merge($autoFilters, $options['document_filter'] ?? []);
            $filters['similarity_threshold'] = $minThreshold;
            $filters['model_name'] = $this->activeEmbeddingModel?->model ?? config('rag.embedding.model', 'text-embedding-3-small');

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
        $chunks = $this->applyDynamicThreshold(array_values($allChunks));
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
        ]);
        $llmTime = (microtime(true) - $t0) * 1000;

        $sources = $this->buildSources($chunks);
        $message = $this->saveAssistantMessage($session, $response->getContent(), $sources);

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

    public function askStream(string $question, array $options = []): Generator
    {
        set_time_limit(120);
        $start = microtime(true);
        $question = $this->normalizeQuestion($question);
        $session = $this->resolveSession($options['session_id'] ?? null, $options['user_id'] ?? $this->userId);
        $this->checkMessageLimit($session);

        $autoFilters = $this->extractFiltersFromQuestion($question);
        $ftsQuery = $this->refineFtsQuery($question, $autoFilters);

        $this->saveUserMessage($session, $question);

        // Resolve relative time references (yesterday, today, …) to literal
        // dates so the LLM doesn't have to guess the current date.
        $llmQuestion = $this->resolveTimeReferences($question);

        // Inherit filters from the previous exchange when the current
        // question doesn't specify any user or project (e.g. follow-ups).
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
                $autoFilters = array_merge($inheritedFilters, $autoFilters);
            }
        }

        // When the question had no filters of its own, also restrict the
        // search to the same documents cited in the previous answer.
        if ($inherited) {
            $prevAssistantMsg = ChatMessage::where('session_id', $session->id)
                ->where('role', 'assistant')
                ->orderBy('created_at', 'desc')
                ->first();
            if ($prevAssistantMsg !== null) {
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
        }

        $llm = $this->resolveLLM($options);

        $isBurmese = preg_match('/[\x{1000}-\x{109F}]/u', $question) === 1;

        yield json_encode([
            'type' => 'status',
            'stage' => 'embedding',
            'message' => $isBurmese ? 'မေးခွန်းအား ထည့်သွင်းနေသည်...' : 'Embedding question...',
        ]);

        $searchQueries = $this->expandQuery($question, $llm);
        $allChunks = [];
        $t0 = microtime(true);

        yield json_encode([
            'type' => 'status',
            'stage' => 'searching',
            'message' => $isBurmese ? 'စာရွက်စာတမ်းများတွင် ရှာဖွေနေသည်...' : 'Searching documents...',
        ]);

        foreach ($searchQueries as $q) {
            $questionVector = $this->embedder->embed($q);
            $minThreshold = min($this->similarityThreshold, 0.40);
            $filters = array_merge($autoFilters, $options['document_filter'] ?? []);
            $filters['similarity_threshold'] = $minThreshold;
            $filters['model_name'] = $this->activeEmbeddingModel?->model ?? config('rag.embedding.model', 'text-embedding-3-small');

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
        $chunks = $this->applyDynamicThreshold(array_values($allChunks));
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
            yield json_encode(['type' => 'answer', 'content' => $refusal['message']['content']]);
            yield json_encode(['type' => 'sources', 'sources' => []]);

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
        $stream = $llm->completeStream($systemPrompt, $llmQuestion, $context, ['temperature' => 0.3]);

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

    public function listSessions(?string $userId = null): array
    {
        $query = ChatSession::orderByDesc('last_activity_at');

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        return $query->paginate(20)->toArray();
    }

    public function getSession(string $id, ?string $userId = null): array
    {
        $query = ChatSession::with('messages')->where('id', $id);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $session = $query->firstOrFail();

        return $session->toArray();
    }

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
            foreach ($projects as $project) {
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
            'ဆိုင်ရာ', 'ပတ်သက်',
        ];

        // Strip matched user names
        if (isset($filters['user_ids'])) {
            try {
                $users = User::whereIn('id', $filters['user_ids'])->pluck('name');
                foreach ($users as $name) {
                    $question = preg_replace('/\b'.preg_quote($name, '/').'\b/iu', '', $question);
                    // Also try stripping just the base name before English parenthetical
                    $baseName = preg_replace('/\s*\([^)]*\)$/u', '', $name);
                    if ($baseName !== $name) {
                        $question = preg_replace('/'.preg_quote($baseName, '/').'/iu', '', $question);
                    }
                }
            } catch (\Throwable) {
                // skip
            }
        }

        // Strip matched project name
        if (isset($filters['project'])) {
            $question = preg_replace('/\b'.preg_quote($filters['project'], '/').'\b/iu', '', $question);
        }

        // Strip date patterns (YYYY-MM-DD or YYYY-MM) before individual
        // year components to avoid leaving bare "-MM" tokens that PostgreSQL
        // plainto_tsquery would interpret as a negation operator.
        $question = preg_replace('/\b\d{4}[-\.\/]\d{1,2}([-\.\/]\d{1,2})?\b/', '', $question);

        // Strip year and quarter patterns
        $question = preg_replace('/\b\d{4}\b/', '', $question);
        $question = preg_replace('/\bQ[1-4]\b/i', '', $question);

        // Strip month names
        $question = preg_replace('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\b/i', '', $question);

        // Strip common stopwords (/iu flag for Burmese word boundaries)
        $pattern = '/\b('.implode('|', array_map('preg_quote', $stopwords)).')\b/iu';
        $question = preg_replace($pattern, '', $question);

        // Keep meaningful content words:
        // - ASCII words: must be > 2 chars, not just punctuation
        // - Non-ASCII words (Burmese, etc.): must be > 1 char, not in
        //   stopwords list. The pg 'english' FTS parser keeps non-ASCII
        //   tokens as-is so they can match document content.
        $words = preg_split('/\s+/', $question);
        $words = array_filter($words, function (string $w): bool {
            $w = trim($w);
            if ($w === '') {
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
            $fallback = preg_replace('/[^\p{L}0-9\s\-\.]/u', '', $question);
            $fallback = preg_replace('/\b\d{4}[-\.\/]\d{1,2}([-\.\/]\d{1,2})?\b/', '', $fallback);
            $fallback = preg_replace('/\b\d{4}\b/', '', $fallback);
            $fallback = preg_replace('/-+/', ' ', $fallback);
            $fallback = trim(preg_replace('/\s+/', ' ', $fallback));

            return $fallback !== '' ? $fallback : 'report';
        }

        return $result;
    }

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
     * Replace relative time references (yesterday, today, this month, …)
     * with their literal date so the LLM doesn't have to guess the current date.
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
            $question = preg_replace($pattern, $replacement, $question);
        }

        return $question;
    }

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

    private function applyDynamicThreshold(array $chunks): array
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

        $cutoff = $this->similarityThreshold;
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

        if ($filtered === [] && $chunks !== []) {
            return array_slice($chunks, 0, min(1, $this->topK));
        }

        return $filtered;
    }

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

    private function normalizeQuestion(string $question): string
    {
        $question = trim($question);
        if ($question === '') {
            throw new \InvalidArgumentException('Question cannot be empty.');
        }
        if (mb_strlen($question) > $this->maxQuestionLength) {
            $question = mb_substr($question, 0, $this->maxQuestionLength);
        }

        return $question;
    }

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

    private function checkMessageLimit(ChatSession $session): void
    {
        $count = $session->messages()->count();
        if ($count >= $this->maxMessagesPerSession) {
            throw new \RuntimeException('Session message limit reached. Please start a new chat.');
        }
    }

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
            if ($nearest !== null && (string) $nearest !== ($from ?? $to)) {
                $dateStr = $nearest instanceof Carbon
                    ? $nearest->toDateString()
                    : (string) $nearest;
                if ($isBurmese) {
                    $hint = "\n\nအနီးစပ်ဆုံးရှိသော အစီရင်ခံစာများမှာ {$dateStr} ရက်စွဲတွင် ရှိပါသည်။";
                } else {
                    $hint = "\n\nThe closest available reports are dated {$dateStr}.";
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

    private function assessConfidence(array $chunks): string
    {
        $aboveThreshold = count($chunks);

        return match (true) {
            $aboveThreshold >= 3 => 'high',
            $aboveThreshold >= 1 => 'low',
            default => 'none',
        };
    }

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
