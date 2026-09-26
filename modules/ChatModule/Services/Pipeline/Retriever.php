<?php

declare(strict_types=1);

namespace Modules\ChatModule\Services\Pipeline;

use Illuminate\Support\Facades\Log;
use Modules\ChatModule\Services\QuestionClassifier;
use Modules\ChatModule\Services\RerankerService;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\SettingsModule\Models\AiModel;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

/**
 * Finds the chunks that answer a question.
 *
 * Optional LLM query expansion → vector or hybrid search per query →
 * de-duplication → dynamic threshold → optional cross-encoder rerank → MMR.
 */
class Retriever
{
    public function __construct(
        private VectorStoreInterface $vectorStore,
        private ChunkProcessor $chunkProcessor,
        private RerankerService $reranker,
        private FilterResolver $filterResolver,
    ) {}

    /**
     * Reformulate the question into extra search queries.
     *
     * Runs when expansion is enabled, or always for analytical/comparative
     * questions where recall matters most. Falls back to the original
     * question alone if the LLM call fails.
     *
     * @param  string  $question  Search question. Example: "Q3 revenue report"
     * @param  string  $questionType  QuestionClassifier type. Example: "analytical"
     * @param  LlmProfile  $profile  LLM used for expansion
     * @param  PipelineSettings  $settings  Pipeline settings
     * @param  array  $requestOptions  Request options (think override). Example: []
     * @return array<string> Queries with the original first. Example: ["Q3 revenue report", "third quarter revenue"]
     */
    public function expandQuery(string $question, string $questionType, LlmProfile $profile, PipelineSettings $settings, array $requestOptions = []): array
    {
        $recallBoost = in_array($questionType, [QuestionClassifier::TYPE_ANALYTICAL, QuestionClassifier::TYPE_COMPARATIVE], true);

        if (! $settings->queryExpansionEnabled && ! $recallBoost) {
            return [$question];
        }

        $n = $settings->numExpansionQueries;
        $prompt = "You are a search query optimizer. Generate {$n} different reformulations of the given question to improve document retrieval. Return ONE reformulation per line, no numbering, no extra text.\n\nQuestion: {$question}";

        try {
            $response = $profile->llm->complete(
                systemPrompt: '',
                userPrompt: $prompt,
                context: [],
                options: $profile->options($requestOptions, maxTokens: 500),
            );

            $lines = array_values(array_filter(
                explode("\n", $response->getContent()),
                fn (string $line): bool => trim($line) !== '',
            ));

            return [$question, ...array_slice($lines, 0, $n)];
        } catch (\Throwable $e) {
            Log::channel(config('rag.logging.channel', 'rag'))->warning('Query expansion failed, using original question', [
                'error' => $e->getMessage(),
            ]);

            return [$question];
        }
    }

    /**
     * Search every query, merge, and filter down to the final chunks.
     *
     * Factual questions always use hybrid search: exact names, numbers and
     * dates match best lexically.
     *
     * @param  array<string>  $queries  Search queries, original first
     * @param  EmbeddingServiceInterface  $embedder  Embedder matching the target documents
     * @param  AiModel|null  $embeddingModel  Model whose vectors to search
     * @param  string  $ftsQuery  Plain FTS query. Example: "Q3 revenue"
     * @param  string|null  $booleanFtsQuery  Boolean FTS query from FtsQueryBuilder::constrainBoolean(). Example: "revenue & q3"
     * @param  array  $filters  Tenant-safe filters from FilterResolver
     * @param  string  $questionType  QuestionClassifier type. Example: "factual"
     * @param  PipelineSettings  $settings  Pipeline settings
     * @param  array  $requestOptions  Request options (document_filter, user_id)
     * @return array{chunks: array<object>, searchTime: float} Final chunks and search time in ms
     */
    public function retrieve(
        array $queries,
        EmbeddingServiceInterface $embedder,
        ?AiModel $embeddingModel,
        string $ftsQuery,
        ?string $booleanFtsQuery,
        array $filters,
        string $questionType,
        PipelineSettings $settings,
        array $requestOptions,
    ): array {
        $mode = $questionType === QuestionClassifier::TYPE_FACTUAL ? 'hybrid' : $settings->searchMode;

        // Defense in depth: re-apply tenant isolation after merging the
        // client-supplied document_filter, even though the controller scoped it.
        $searchFilters = $this->filterResolver->applyTenantIsolation(
            array_merge($filters, $requestOptions['document_filter'] ?? []),
            $requestOptions['user_id'] ?? null,
        );
        $searchFilters['similarity_threshold'] = $settings->minInitialThreshold;
        $searchFilters['model_name'] = AiModel::resolveModelName($embeddingModel, 'embedding');
        if ($booleanFtsQuery !== null) {
            $searchFilters['boolean_fts_query'] = $booleanFtsQuery;
        }

        $candidates = [];
        $t0 = microtime(true);

        foreach ($queries as $query) {
            $vector = $embedder->embed($query);
            $found = $mode === 'hybrid'
                ? $this->vectorStore->searchHybrid($ftsQuery, $vector, $settings->topK * 3, $searchFilters)
                : $this->vectorStore->search($vector, $settings->topK * 3, $searchFilters);

            foreach ($found as $chunk) {
                $candidates[$chunk->chunk_id] ??= $chunk;
            }
        }

        $searchTime = (microtime(true) - $t0) * 1000;

        $chunks = array_values($candidates);
        usort($chunks, fn (object $a, object $b): int => (float) $b->similarity_score <=> (float) $a->similarity_score);
        $chunks = $this->chunkProcessor->applyDynamicThreshold($chunks, $filters, $settings->similarityThreshold, $settings->topK * 2);

        // No-op unless rag.reranker.enabled.
        $chunks = $this->reranker->rerank($queries[0], $chunks);

        $chunks = $this->chunkProcessor->applyMMR($chunks, $settings->mmrEnabled, $settings->topK, $settings->mmrLambda);

        return ['chunks' => $chunks, 'searchTime' => $searchTime];
    }
}
