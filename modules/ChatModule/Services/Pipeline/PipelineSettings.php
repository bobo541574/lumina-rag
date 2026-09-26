<?php

declare(strict_types=1);

namespace Modules\ChatModule\Services\Pipeline;

use Modules\SettingsModule\Models\AiModel;

/**
 * Tunables for one RAG pipeline instance.
 *
 * Defaults come from config/rag.php (fromConfig()); the active AiModels'
 * `settings` JSON can override them (withModelOverrides()), following the
 * documented priority: AiModel > config > code defaults.
 */
final class PipelineSettings
{
    public function __construct(
        public readonly int $topK = 5,
        public readonly float $similarityThreshold = 0.65,
        public readonly int $maxQuestionLength = 1000,
        public readonly int $maxMessagesPerSession = 100,
        public readonly string $searchMode = 'hybrid',
        public readonly bool $queryExpansionEnabled = false,
        public readonly int $numExpansionQueries = 3,
        public readonly bool $mmrEnabled = true,
        public readonly float $mmrLambda = 0.7,
        public readonly int $maxTokens = 4096,
        public readonly float $minInitialThreshold = 0.20,
        public readonly int $historyWindow = 4,
        public readonly bool $cacheEnabled = true,
        public readonly ?bool $think = null,
    ) {}

    /**
     * Build settings from config/rag.php.
     *
     * @return self Example: PipelineSettings::fromConfig()->topK → 5
     */
    public static function fromConfig(): self
    {
        return new self(
            topK: (int) config('rag.search.top_k', 5),
            similarityThreshold: (float) config('rag.search.similarity_threshold', 0.65),
            maxQuestionLength: (int) config('rag.chat.max_question_length', 1000),
            maxMessagesPerSession: (int) config('rag.chat.max_messages_per_session', 100),
            searchMode: (string) config('rag.search.mode', 'hybrid'),
            queryExpansionEnabled: (bool) config('rag.search.query_expansion.enabled', false),
            numExpansionQueries: (int) config('rag.search.query_expansion.num_queries', 3),
            mmrEnabled: (bool) config('rag.search.mmr.enabled', true),
            mmrLambda: (float) config('rag.search.mmr.lambda', 0.7),
            maxTokens: (int) config('rag.llm.max_tokens', 4096),
            minInitialThreshold: (float) config('rag.search.min_initial_threshold', 0.20),
            historyWindow: (int) config('rag.chat.history_window', 4),
            cacheEnabled: (bool) config('rag.cache.enabled', true),
        );
    }

    /**
     * Apply per-model overrides from the active models' `settings` JSON.
     *
     * Retrieval knobs come from the embedding model, generation/chat knobs
     * from the LLM model.
     *
     * @param  AiModel|null  $embedding  Active embedding model. Example: AiModel::active()->embedding()->first()
     * @param  AiModel|null  $llm  Active LLM model. Example: AiModel::active()->llm()->first()
     * @return self New settings instance
     */
    public function withModelOverrides(?AiModel $embedding, ?AiModel $llm): self
    {
        $e = $embedding?->settings ?? [];
        $l = $llm?->settings ?? [];

        return new self(
            topK: isset($e['top_k']) ? (int) $e['top_k'] : $this->topK,
            similarityThreshold: isset($e['similarity_threshold']) ? (float) $e['similarity_threshold'] : $this->similarityThreshold,
            maxQuestionLength: isset($l['max_question_length']) ? (int) $l['max_question_length'] : $this->maxQuestionLength,
            maxMessagesPerSession: isset($l['max_messages_per_session']) ? (int) $l['max_messages_per_session'] : $this->maxMessagesPerSession,
            searchMode: isset($e['search_mode']) ? (string) $e['search_mode'] : $this->searchMode,
            queryExpansionEnabled: isset($e['query_expansion_enabled']) ? (bool) $e['query_expansion_enabled'] : $this->queryExpansionEnabled,
            numExpansionQueries: isset($e['num_expansion_queries']) ? (int) $e['num_expansion_queries'] : $this->numExpansionQueries,
            mmrEnabled: isset($e['mmr_enabled']) ? (bool) $e['mmr_enabled'] : $this->mmrEnabled,
            mmrLambda: isset($e['mmr_lambda']) ? (float) $e['mmr_lambda'] : $this->mmrLambda,
            maxTokens: isset($l['max_tokens']) ? (int) $l['max_tokens'] : $this->maxTokens,
            minInitialThreshold: $this->minInitialThreshold,
            historyWindow: $this->historyWindow,
            cacheEnabled: $this->cacheEnabled,
            think: isset($l['think']) ? (bool) $l['think'] : $this->think,
        );
    }
}
