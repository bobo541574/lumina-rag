<?php

declare(strict_types=1);

namespace Modules\ChatModule\Services;

use Generator;
use Illuminate\Support\Facades\Log;
use Modules\ChatModule\Contracts\RAGPipelineServiceInterface;
use Modules\ChatModule\Exceptions\ChatSessionException;
use Modules\ChatModule\Models\ChatMessage;
use Modules\ChatModule\Models\ChatSession;
use Modules\ChatModule\Services\Pipeline\AnswerCache;
use Modules\ChatModule\Services\Pipeline\ChunkProcessor;
use Modules\ChatModule\Services\Pipeline\FilterExtractor;
use Modules\ChatModule\Services\Pipeline\FilterResolver;
use Modules\ChatModule\Services\Pipeline\FtsQueryBuilder;
use Modules\ChatModule\Services\Pipeline\LlmProfile;
use Modules\ChatModule\Services\Pipeline\PipelineSettings;
use Modules\ChatModule\Services\Pipeline\PromptAssembler;
use Modules\ChatModule\Services\Pipeline\QueryRewriterService;
use Modules\ChatModule\Services\Pipeline\ResponseBuilder;
use Modules\ChatModule\Services\Pipeline\Retriever;
use Modules\ChatModule\Services\Pipeline\RewrittenQuery;
use Modules\ChatModule\Services\Pipeline\SessionManager;
use Modules\DocumentModule\Models\Document;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\EmbeddingModule\Services\ProviderFactory;
use Modules\LLMModule\Contracts\LLMServiceInterface;
use Modules\LLMModule\Services\LLMService;
use Modules\SettingsModule\Contracts\TermAliasServiceInterface;
use Modules\SettingsModule\Models\AiModel;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;
use Psr\Log\LoggerInterface;

/**
 * RAG Pipeline Service
 *
 * Orchestrates one question end to end:
 * prepare (normalise, classify, session, rewrite, filters, model selection)
 * → semantic cache → query expansion → retrieval → prompt → LLM → persist.
 *
 * ask() and askStream() run the same generator (run()); the only difference
 * is whether the LLM call streams. Each stage lives in a Pipeline/ helper:
 * FilterResolver, Retriever, PromptAssembler, AnswerCache, LlmProfile.
 *
 * Settings come from PipelineSettings (config/rag.php), overridden by the
 * active AiModels' `settings`; the active models' providers replace the
 * injected fallback embedder/LLM.
 *
 * @param  EmbeddingServiceInterface  $embedder  Fallback embedder when no embedding AiModel is active
 * @param  VectorStoreInterface  $vectorStore  Vector/hybrid search backend
 * @param  LLMServiceInterface  $llm  Fallback LLM when no LLM AiModel is active
 * @param  ProviderFactory  $providerFactory  Builds providers for AiModel records
 * @param  PipelineSettings  $baseSettings  Config-level settings. Example: PipelineSettings::fromConfig()
 * @param  string|null  $userId  Default user when a request carries none. Example: "01J..."
 * @param  string|null  $activeEmbeddingModelId  Force a specific embedding AiModel. Example: "01J..."
 * @param  string|null  $activeLlmModelId  Force a specific LLM AiModel. Example: "01J..."
 */
class RAGPipelineService implements RAGPipelineServiceInterface
{
    private const TRUNCATION_NOTE = "\n\n*[မှတ်ချက်: အဖြေသည် သတ်မှတ်ထားသော token ကန့်သတ်ချက်ကို ကျော်လွန်နေသောကြောင့် ဖြတ်တောက်ထားပါသည်။ ထပ်မံမေးမြန်းနိုင်ပါသည်။]*";

    /** @var PipelineSettings Settings after the active models' overrides */
    private PipelineSettings $settings;

    private ?AiModel $activeEmbeddingModel = null;

    private ?AiModel $activeLlmModel = null;

    private FilterResolver $filterResolver;

    private Retriever $retriever;

    private PromptAssembler $promptAssembler;

    private AnswerCache $answerCache;

    public function __construct(
        private EmbeddingServiceInterface $embedder,
        VectorStoreInterface $vectorStore,
        private LLMServiceInterface $llm,
        private ProviderFactory $providerFactory,
        private TermAliasServiceInterface $termAliasService,
        private FilterExtractor $filterExtractor,
        private FtsQueryBuilder $ftsQueryBuilder,
        private QueryRewriterService $queryRewriter,
        ChunkProcessor $chunkProcessor,
        private ResponseBuilder $responseBuilder,
        private SessionManager $sessionManager,
        SemanticCacheService $semanticCache,
        private QuestionClassifier $questionClassifier,
        RerankerService $reranker,
        private PipelineSettings $baseSettings = new PipelineSettings,
        private ?string $userId = null,
        ?string $activeEmbeddingModelId = null,
        ?string $activeLlmModelId = null,
    ) {
        try {
            $this->activeEmbeddingModel = ($activeEmbeddingModelId !== null ? AiModel::find($activeEmbeddingModelId) : null)
                ?? AiModel::active()->embedding()->orderBy('sort_order')->first();
            $this->activeLlmModel = ($activeLlmModelId !== null ? AiModel::find($activeLlmModelId) : null)
                ?? AiModel::active()->llm()->orderBy('sort_order')->first();
        } catch (\Throwable) {
            // DB not available yet — run on config fallbacks.
        }

        $this->settings = $baseSettings->withModelOverrides($this->activeEmbeddingModel, $this->activeLlmModel);

        if ($this->activeLlmModel !== null) {
            $this->llm = $this->buildLlm($this->activeLlmModel);
        }
        if ($this->activeEmbeddingModel !== null) {
            $this->embedder = $this->providerFactory->createEmbeddingService($this->activeEmbeddingModel);
        }

        $this->filterResolver = new FilterResolver($filterExtractor);
        $this->retriever = new Retriever($vectorStore, $chunkProcessor, $reranker, $this->filterResolver);
        $this->promptAssembler = new PromptAssembler($chunkProcessor, $responseBuilder, $this->settings->historyWindow);
        $this->answerCache = new AnswerCache($semanticCache, $this->settings->cacheEnabled);
    }

    /**
     * Answer a question synchronously.
     *
     * @param  string  $question  The user's question. Example: "What is the revenue for Q3?"
     * @param  array  $options  session_id, document_filter, user_id, llm_model_id, think. Example: ["session_id" => "01J..."]
     * @return array{session_id: string, message: array} Assistant message with sources
     *
     * @throws \InvalidArgumentException When the question is empty
     * @throws ChatSessionException When the session cannot be used
     */
    public function ask(string $question, array $options = []): array
    {
        foreach ($this->run($question, $options, stream: false) as $event) {
            if ($event['type'] === 'result') {
                return $event['response'];
            }
        }

        throw new \LogicException('RAG pipeline finished without a result.');
    }

    /**
     * Answer a question as JSON-encoded SSE events.
     *
     * Events: rewrite_info, status, cached, sources, chunk, done.
     *
     * @param  string  $question  The user's question. Example: "What is the revenue for Q3?"
     * @param  array  $options  session_id, document_filter, user_id, llm_model_id, think. Example: ["session_id" => "01J..."]
     * @return Generator<string> JSON-encoded events. Example: '{"type":"chunk","content":"Revenue"}'
     *
     * @throws \InvalidArgumentException When the question is empty
     * @throws ChatSessionException When the session cannot be used
     */
    public function askStream(string $question, array $options = []): Generator
    {
        foreach ($this->run($question, $options, stream: true) as $event) {
            if ($event['type'] !== 'result') {
                yield json_encode($event);
            }
        }
    }

    /**
     * The pipeline shared by ask() and askStream().
     *
     * Yields client events, then a final internal `result` event holding the
     * response ask() returns.
     *
     * @param  string  $question  The user's question
     * @param  array  $options  Request options
     * @param  bool  $stream  Stream the LLM answer (true) or complete it in one call
     * @return Generator<array> Event arrays
     */
    private function run(string $question, array $options, bool $stream): Generator
    {
        set_time_limit(300);
        $start = microtime(true);
        $options['user_id'] ??= $this->userId;

        [
            'question' => $question, 'questionType' => $questionType, 'session' => $session,
            'rewritten' => $rewritten, 'searchQuestion' => $searchQuestion, 'ftsQuery' => $ftsQuery,
            'booleanFtsQuery' => $booleanFtsQuery, 'llmQuestion' => $llmQuestion, 'filters' => $filters, 'profile' => $profile,
            'embeddingModel' => $embeddingModel, 'embedder' => $embedder,
        ] = $this->prepare($question, $options);

        $isBurmese = preg_match('/[\x{1000}-\x{109F}]/u', $question) === 1;
        $status = fn (string $stage, string $my, string $en): array => ['type' => 'status', 'stage' => $stage, 'message' => $isBurmese ? $my : $en];
        $elapsed = fn (float $since): float => round((microtime(true) - $since) * 1000, 1);
        $logContext = [
            'session_id' => $session->id,
            'mode' => $stream ? 'stream' : 'sync',
            'question_length' => mb_strlen($question),
            'rewrite_score' => $rewritten->score,
            'rewrite_mode' => $rewritten->mode,
        ];

        yield [
            'type' => 'rewrite_info',
            'score' => $rewritten->score,
            'mode' => $rewritten->mode,
            'embedding_text' => $rewritten->embeddingText,
            'fts_query' => $rewritten->ftsQuery,
            'question_type' => $questionType,
        ];
        yield $status('embedding', 'မေးခွန်းအား ထည့်သွင်းနေသည်...', 'Embedding question...');

        // A near-identical question answered against the same knowledge
        // version skips retrieval and generation entirely.
        $cached = $this->answerCache->lookup($searchQuestion, $embedder, $session, $profile);
        if ($cached !== null) {
            $message = $this->sessionManager->saveAssistantMessage($session, $cached['answer'], $cached['sources']);
            $this->log()->info('RAG pipeline: semantic cache hit', $logContext + ['cached_question' => $cached['question'] ?? $question]);

            yield ['type' => 'cached', 'sources' => $cached['sources']];
            yield ['type' => 'sources', 'sources' => $cached['sources']];
            yield ['type' => 'chunk', 'content' => $cached['answer']];
            yield ['type' => 'done', 'session_id' => $session->id, 'search_time_ms' => 0, 'llm_time_ms' => 0,
                'total_time_ms' => $elapsed($start), 'tokens_used' => 0, 'cached' => true];
            yield ['type' => 'result', 'response' => $this->messageResponse($session, $message, $cached['answer'], $cached['sources'], 0, cached: true)];

            return;
        }

        $queries = $this->retriever->expandQuery($searchQuestion, $questionType, $profile, $this->settings, $options);
        yield $status('searching', 'စာရွက်စာတမ်းများတွင် ရှာဖွေနေသည်...', 'Searching documents...');

        ['chunks' => $chunks, 'searchTime' => $searchTime] = $this->retriever->retrieve(
            $queries, $embedder, $embeddingModel, $ftsQuery, $booleanFtsQuery, $filters, $questionType, $this->settings, $options,
        );

        // Nothing relevant: refuse without calling the LLM.
        if ($chunks === []) {
            $refusal = $this->responseBuilder->buildRefusalResponse($session, $question, $filters);
            $content = (string) ($refusal['message']['content'] ?? '');
            $this->answerCache->store($searchQuestion, $embedder, $profile, $session->user_id, $question, $content, [], true);
            $this->log()->info('RAG pipeline: refusal (no chunks)', $logContext + [
                'search_time_ms' => round($searchTime, 1),
                'total_time_ms' => $elapsed($start),
            ]);

            yield ['type' => 'sources', 'sources' => []];
            yield ['type' => 'chunk', 'content' => $content];
            yield ['type' => 'done', 'session_id' => $session->id, 'search_time_ms' => round($searchTime, 1),
                'llm_time_ms' => 0, 'total_time_ms' => $elapsed($start), 'tokens_used' => 0];
            yield ['type' => 'result', 'response' => $refusal];

            return;
        }

        $prompt = $this->promptAssembler->assemble($chunks, $filters, $llmQuestion, $session);
        $sources = $this->responseBuilder->buildSources($chunks);
        yield ['type' => 'sources', 'sources' => $sources];
        yield $status('generating', 'အဖြေထုတ်လုပ်နေသည်...', 'Generating answer...');

        $t0 = microtime(true);
        $llmOptions = $profile->options($options);
        if ($stream) {
            $content = '';
            foreach ($profile->llm->completeStream($prompt['systemPrompt'], $prompt['userPrompt'], $prompt['context'], $llmOptions) as $delta) {
                $content .= $delta;
                yield ['type' => 'chunk', 'content' => $delta];
            }
            $tokensUsed = $profile->llm->countTokens($content);
            $usage = ['response_length' => mb_strlen($content)];
        } else {
            $response = $profile->llm->complete($prompt['systemPrompt'], $prompt['userPrompt'], $prompt['context'], $llmOptions);
            $content = $response->getContent().($response->getFinishReason() === 'length' ? self::TRUNCATION_NOTE : '');
            yield ['type' => 'chunk', 'content' => $content];
            $tokensUsed = $response->getTotalTokens();
            $usage = [
                'prompt_tokens' => $response->getPromptTokens(),
                'completion_tokens' => $response->getCompletionTokens(),
                'total_tokens' => $tokensUsed,
            ];
        }
        $llmTime = $elapsed($t0);

        $message = $this->sessionManager->saveAssistantMessage($session, $content, $sources);
        $this->answerCache->store($searchQuestion, $embedder, $profile, $session->user_id, $question, $content, $sources, false);

        $this->log()->info('RAG pipeline: complete', $logContext + [
            'chunks_found' => count($chunks),
            'search_time_ms' => round($searchTime, 1),
            'llm_time_ms' => $llmTime,
            'total_time_ms' => $elapsed($start),
        ] + $usage);

        yield ['type' => 'done', 'session_id' => $session->id, 'search_time_ms' => round($searchTime, 1),
            'llm_time_ms' => $llmTime, 'total_time_ms' => $elapsed($start), 'tokens_used' => $tokensUsed];
        yield ['type' => 'result', 'response' => $this->messageResponse($session, $message, $content, $sources, $tokensUsed)];
    }

    /**
     * Everything the pipeline needs before searching.
     *
     * Order matters: filters are extracted before the user message is saved
     * (FTS refinement uses them), and inheritance runs after (it skips the
     * just-saved message to find the previous question).
     *
     * @param  string  $question  Raw question
     * @param  array  $options  Request options with user_id resolved
     * @return array{question: string, questionType: string, session: ChatSession, rewritten: RewrittenQuery, searchQuestion: string, ftsQuery: string, booleanFtsQuery: string|null, llmQuestion: string, filters: array, profile: LlmProfile, embeddingModel: AiModel|null, embedder: EmbeddingServiceInterface}
     */
    private function prepare(string $question, array $options): array
    {
        $question = $this->normalizeQuestion($question);
        $questionType = $this->questionClassifier->classify($question)['type'];

        $session = $this->sessionManager->resolveSession($options['session_id'] ?? null, $options['user_id']);
        $this->sessionManager->checkMessageLimit($session, $this->settings->maxMessagesPerSession);

        $extracted = $this->filterExtractor->extract($question);
        $rewritten = $this->queryRewriter->rewrite($question);
        $searchQuestion = $this->termAliasService->expandText($rewritten->embeddingText);
        $refined = $this->ftsQueryBuilder->refine($rewritten->ftsQuery ?? $question, $extracted);
        $ftsQuery = $this->termAliasService->expandFtsQuery($refined);
        $booleanFtsQuery = $this->ftsQueryBuilder->constrainBoolean($rewritten->ftsQuery, $refined);

        $this->sessionManager->saveUserMessage($session, $question);

        $filters = $this->filterResolver->resolve($extracted, $session, $options['user_id']);
        [$embeddingModel, $embedder] = $this->embedderFor($filters);

        return [
            'question' => $question,
            'questionType' => $questionType,
            'session' => $session,
            'rewritten' => $rewritten,
            'searchQuestion' => $searchQuestion,
            'ftsQuery' => $ftsQuery,
            'booleanFtsQuery' => $booleanFtsQuery,
            'llmQuestion' => $this->filterExtractor->resolveTimeReferences($question),
            'filters' => $filters,
            'profile' => $this->llmProfileFor($options),
            'embeddingModel' => $embeddingModel,
            'embedder' => $embedder,
        ];
    }

    /**
     * Pick the embedder whose vectors the targeted documents were built with.
     *
     * When the scoped documents were all embedded with one model other than
     * the active one, searching with the active model's vectors would never
     * match, so that model's embedder is used instead.
     *
     * @param  array  $filters  Resolved search filters
     * @return array{0: AiModel|null, 1: EmbeddingServiceInterface}
     */
    private function embedderFor(array $filters): array
    {
        $default = [$this->activeEmbeddingModel, $this->embedder];

        if (empty($filters['user_ids']) && empty($filters['project']) && empty($filters['report_date_from'])) {
            return $default;
        }

        try {
            $modelIds = Document::query()
                ->when(! empty($filters['user_ids']), fn ($q) => $q->whereIn('user_id', (array) $filters['user_ids']))
                ->when(! empty($filters['project']), fn ($q) => $q->where('project', $filters['project']))
                ->when(! empty($filters['report_date_from']), fn ($q) => $q->where('report_date', '>=', $filters['report_date_from']))
                ->when(! empty($filters['report_date_to']), fn ($q) => $q->where('report_date', '<=', $filters['report_date_to']))
                ->whereNotNull('embedding_model_id')
                ->distinct()
                ->pluck('embedding_model_id');

            if ($modelIds->count() !== 1 || $modelIds->first() === $this->activeEmbeddingModel?->id) {
                return $default;
            }

            $model = AiModel::find($modelIds->first());

            return $model !== null ? [$model, $this->providerFactory->createEmbeddingService($model)] : $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * The LLM (and its parameters) answering this request.
     *
     * Honours a valid `llm_model_id` (active, type llm); otherwise the default
     * model. An override model reads its own settings on top of the config
     * defaults, not the default model's overrides.
     *
     * @param  array  $options  Request options. Example: ["llm_model_id" => "01J..."]
     */
    private function llmProfileFor(array $options): LlmProfile
    {
        $requested = $options['llm_model_id'] ?? null;
        $override = $requested !== null && $requested !== $this->activeLlmModel?->id ? AiModel::find($requested) : null;

        if ($override === null || ! $override->is_active || $override->type !== 'llm') {
            return LlmProfile::for($this->llm, $this->activeLlmModel, $this->settings->maxTokens, $this->settings->think);
        }

        $settings = $this->baseSettings->withModelOverrides(null, $override);

        return LlmProfile::for($this->buildLlm($override), $override, $settings->maxTokens, $settings->think);
    }

    /**
     * @param  AiModel  $model  LLM model record
     * @return LLMServiceInterface Client with the model's context budget
     */
    private function buildLlm(AiModel $model): LLMServiceInterface
    {
        return new LLMService(
            provider: $this->providerFactory->createLLMProvider($model),
            maxContextTokens: $model->maxContextTokens(),
        );
    }

    /**
     * Trim, validate, truncate, and spell out YYYY-MM as YYYY-Month so the
     * embedding and FTS see the month name ("2026-05" → "2026-May").
     *
     * @param  string  $question  Raw question. Example: "  Report for 2026-05?  "
     * @return string Example: "Report for 2026-May?"
     *
     * @throws \InvalidArgumentException When the question is empty
     */
    private function normalizeQuestion(string $question): string
    {
        $question = trim($question);
        if ($question === '') {
            throw new \InvalidArgumentException('Question cannot be empty.');
        }

        $question = mb_substr($question, 0, $this->settings->maxQuestionLength);

        return preg_replace_callback(
            '/\b(\d{4})-(0[1-9]|1[0-2])\b(?!\s*-?\s*\d{1,2}\b)/',
            fn (array $m): string => $m[1].'-'.array_keys(FilterExtractor::MONTHS)[(int) $m[2] - 1],
            $question,
        );
    }

    /**
     * @return array{session_id: string, message: array} Response envelope for ask()
     */
    private function messageResponse(ChatSession $session, ChatMessage $message, string $content, array $sources, int $tokensUsed, bool $cached = false): array
    {
        return [
            'session_id' => $session->id,
            'message' => [
                'id' => $message->id,
                'role' => 'assistant',
                'content' => $content,
                'sources' => $sources,
                'tokens_used' => $tokensUsed,
                'created_at' => $message->created_at->toIso8601String(),
            ] + ($cached ? ['cached' => true] : []),
        ];
    }

    private function log(): LoggerInterface
    {
        return Log::channel(config('rag.logging.channel', 'rag'));
    }
}
