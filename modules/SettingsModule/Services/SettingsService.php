<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Services;

use Modules\SettingsModule\Models\Setting;

class SettingsService
{
    private const ENV_TO_CONFIG = [
        'RAG_EMBEDDING_PROVIDER' => 'rag.embedding.provider',
        'RAG_EMBEDDING_MODEL' => 'rag.embedding.model',
        'RAG_EMBEDDING_DIMENSIONS' => 'rag.embedding.dimensions',
        'RAG_EMBEDDING_BATCH_SIZE' => 'rag.embedding.batch_size',
        'RAG_EMBEDDING_CACHE_TTL' => 'rag.embedding.cache_ttl',
        'RAG_EMBEDDING_TIMEOUT' => 'rag.embedding.timeout',
        'RAG_EMBEDDING_BASE_URL' => 'rag.embedding.base_url',
        'RAG_LLM_PROVIDER' => 'rag.llm.provider',
        'RAG_LLM_MODEL' => 'rag.llm.model',
        'RAG_LLM_TEMPERATURE' => 'rag.llm.temperature',
        'RAG_LLM_MAX_CONTEXT_TOKENS' => 'rag.llm.max_context_tokens',
        'RAG_LLM_TIMEOUT' => 'rag.llm.timeout',
        'RAG_LLM_BASE_URL' => 'rag.llm.base_url',
        'RAG_VECTOR_DRIVER' => 'rag.vector_store.driver',
        'RAG_VECTOR_INDEX_LISTS' => 'rag.vector_store.index_lists',
        'RAG_SEARCH_MODE' => 'rag.search.mode',
        'RAG_SEARCH_TOP_K' => 'rag.search.top_k',
        'RAG_SEARCH_SIMILARITY_THRESHOLD' => 'rag.search.similarity_threshold',
        'RAG_SEARCH_HYBRID_VECTOR_WEIGHT' => 'rag.search.hybrid.vector_weight',
        'RAG_SEARCH_HYBRID_FTS_WEIGHT' => 'rag.search.hybrid.fts_weight',
        'RAG_QUERY_EXPANSION_ENABLED' => 'rag.search.query_expansion.enabled',
        'RAG_QUERY_EXPANSION_NUM_QUERIES' => 'rag.search.query_expansion.num_queries',
        'RAG_SEARCH_MMR_ENABLED' => 'rag.search.mmr.enabled',
        'RAG_SEARCH_MMR_LAMBDA' => 'rag.search.mmr.lambda',
        'RAG_CHUNK_SIZE' => 'rag.chunking.chunk_size',
        'RAG_CHUNK_OVERLAP' => 'rag.chunking.overlap',
        'RAG_MAX_QUESTION_LENGTH' => 'rag.chat.max_question_length',
        'RAG_MAX_MESSAGES_PER_SESSION' => 'rag.chat.max_messages_per_session',
        'RAG_LOG_CHANNEL' => 'rag.logging.channel',
        'RAG_LOG_LEVEL' => 'rag.logging.level',
        'RAG_EMBEDDING_AVAILABLE_MODELS' => 'rag.embedding.available_models',
        'RAG_LLM_AVAILABLE_MODELS' => 'rag.llm.available_models',
    ];

    public function loadIntoConfig(): void
    {
        $settings = Setting::all();

        foreach ($settings as $setting) {
            $configPath = self::ENV_TO_CONFIG[$setting->key] ?? null;

            if ($configPath === null) {
                continue;
            }

            $value = $this->castValue($setting->value, $setting->type);
            config()->set($configPath, $value);
        }
    }

    public function getAll(): array
    {
        $settings = Setting::orderBy('group')->orderBy('key')->get();
        $definitions = $this->getDefinitions();

        return $settings->map(fn (Setting $s) => [
            'id' => $s->id,
            'key' => $s->key,
            'value' => $s->value,
            'type' => $s->type,
            'label' => $s->label ?? $definitions[$s->key]['label'] ?? $s->key,
            'group' => $s->group ?? $definitions[$s->key]['group'] ?? 'general',
        ])->toArray();
    }

    public function set(string $key, mixed $value, ?string $type = null): Setting
    {
        $definitions = $this->getDefinitions();

        if ($type === null) {
            $type = $definitions[$key]['type'] ?? 'string';
        }

        $stringValue = match ($type) {
            'bool' => $value ? 'true' : 'false',
            'int' => (string) (int) $value,
            'float' => (string) (float) $value,
            'json' => is_string($value) ? $value : json_encode($value),
            default => (string) $value,
        };

        $setting = Setting::updateOrCreate(
            ['key' => $key],
            [
                'value' => $stringValue,
                'type' => $type,
                'label' => $definitions[$key]['label'] ?? $key,
                'group' => $definitions[$key]['group'] ?? 'general',
            ],
        );

        $configPath = self::ENV_TO_CONFIG[$key] ?? null;
        if ($configPath !== null) {
            $v = $this->castValue($stringValue, $type);
            config()->set($configPath, $v);
        }

        return $setting;
    }

    public function delete(string $key): void
    {
        Setting::where('key', $key)->delete();
    }

    public function getDefinitions(): array
    {
        return [
            'RAG_EMBEDDING_PROVIDER' => [
                'label' => 'Embedding Provider',
                'group' => 'embedding',
                'type' => 'string',
                'options' => ['openai', 'ollama'],
            ],
            'RAG_EMBEDDING_MODEL' => [
                'label' => 'Embedding Model',
                'group' => 'embedding',
                'type' => 'string',
            ],
            'RAG_EMBEDDING_DIMENSIONS' => [
                'label' => 'Embedding Dimensions',
                'group' => 'embedding',
                'type' => 'int',
            ],
            'RAG_EMBEDDING_BATCH_SIZE' => [
                'label' => 'Embedding Batch Size',
                'group' => 'embedding',
                'type' => 'int',
            ],
            'RAG_EMBEDDING_CACHE_TTL' => [
                'label' => 'Embedding Cache TTL (seconds)',
                'group' => 'embedding',
                'type' => 'int',
            ],
            'RAG_EMBEDDING_TIMEOUT' => [
                'label' => 'Embedding Timeout (seconds)',
                'group' => 'embedding',
                'type' => 'int',
            ],
            'RAG_EMBEDDING_BASE_URL' => [
                'label' => 'Embedding Base URL',
                'group' => 'embedding',
                'type' => 'string',
            ],
            'RAG_LLM_PROVIDER' => [
                'label' => 'LLM Provider',
                'group' => 'llm',
                'type' => 'string',
                'options' => ['openai', 'ollama'],
            ],
            'RAG_LLM_MODEL' => [
                'label' => 'LLM Model',
                'group' => 'llm',
                'type' => 'string',
            ],
            'RAG_LLM_TEMPERATURE' => [
                'label' => 'LLM Temperature',
                'group' => 'llm',
                'type' => 'float',
            ],
            'RAG_LLM_MAX_CONTEXT_TOKENS' => [
                'label' => 'LLM Max Context Tokens',
                'group' => 'llm',
                'type' => 'int',
            ],
            'RAG_LLM_TIMEOUT' => [
                'label' => 'LLM Timeout (seconds)',
                'group' => 'llm',
                'type' => 'int',
            ],
            'RAG_LLM_BASE_URL' => [
                'label' => 'LLM Base URL',
                'group' => 'llm',
                'type' => 'string',
            ],
            'RAG_VECTOR_DRIVER' => [
                'label' => 'Vector Store Driver',
                'group' => 'vector_store',
                'type' => 'string',
                'options' => ['pgsql'],
            ],
            'RAG_VECTOR_INDEX_LISTS' => [
                'label' => 'Vector Index Lists',
                'group' => 'vector_store',
                'type' => 'int',
            ],
            'RAG_SEARCH_MODE' => [
                'label' => 'Search Mode',
                'group' => 'search',
                'type' => 'string',
                'options' => ['vector', 'hybrid'],
            ],
            'RAG_SEARCH_TOP_K' => [
                'label' => 'Search Top K',
                'group' => 'search',
                'type' => 'int',
            ],
            'RAG_SEARCH_SIMILARITY_THRESHOLD' => [
                'label' => 'Similarity Threshold',
                'group' => 'search',
                'type' => 'float',
            ],
            'RAG_SEARCH_HYBRID_VECTOR_WEIGHT' => [
                'label' => 'Hybrid Vector Weight',
                'group' => 'search',
                'type' => 'float',
            ],
            'RAG_SEARCH_HYBRID_FTS_WEIGHT' => [
                'label' => 'Hybrid FTS Weight',
                'group' => 'search',
                'type' => 'float',
            ],
            'RAG_QUERY_EXPANSION_ENABLED' => [
                'label' => 'Query Expansion Enabled',
                'group' => 'search',
                'type' => 'bool',
            ],
            'RAG_QUERY_EXPANSION_NUM_QUERIES' => [
                'label' => 'Query Expansion Num Queries',
                'group' => 'search',
                'type' => 'int',
            ],
            'RAG_SEARCH_MMR_ENABLED' => [
                'label' => 'MMR Enabled',
                'group' => 'search',
                'type' => 'bool',
            ],
            'RAG_SEARCH_MMR_LAMBDA' => [
                'label' => 'MMR Lambda',
                'group' => 'search',
                'type' => 'float',
            ],
            'RAG_CHUNK_SIZE' => [
                'label' => 'Chunk Size (chars)',
                'group' => 'chunking',
                'type' => 'int',
            ],
            'RAG_CHUNK_OVERLAP' => [
                'label' => 'Chunk Overlap (chars)',
                'group' => 'chunking',
                'type' => 'int',
            ],
            'RAG_MAX_QUESTION_LENGTH' => [
                'label' => 'Max Question Length (chars)',
                'group' => 'chat',
                'type' => 'int',
            ],
            'RAG_MAX_MESSAGES_PER_SESSION' => [
                'label' => 'Max Messages Per Session',
                'group' => 'chat',
                'type' => 'int',
            ],
            'RAG_EMBEDDING_AVAILABLE_MODELS' => [
                'label' => 'Available Embedding Models',
                'group' => 'embedding',
                'type' => 'json',
            ],
            'RAG_LLM_AVAILABLE_MODELS' => [
                'label' => 'Available LLM Models',
                'group' => 'llm',
                'type' => 'json',
            ],
            'RAG_LOG_CHANNEL' => [
                'label' => 'Log Channel',
                'group' => 'logging',
                'type' => 'string',
            ],
            'RAG_LOG_LEVEL' => [
                'label' => 'Log Level',
                'group' => 'logging',
                'type' => 'string',
            ],
        ];
    }

    private function castValue(?string $value, string $type): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => $value === 'true' || $value === '1' || $value === 'yes',
            'json' => $value !== null ? json_decode($value, true) : null,
            default => $value,
        };
    }
}
