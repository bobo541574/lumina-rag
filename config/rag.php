<?php

declare(strict_types=1);
use Modules\SettingsModule\Models\AiModel;

/**
 * RAG Configuration
 *
 * Central configuration file for the RAG (Retrieval-Augmented Generation)
 * system. All settings are read via env() with sensible defaults, allowing
 * overrides through environment variables prefixed with RAG_.
 *
 * Configuration sections:
 * - embedding: Provider, model, dimensions, batch size, cache TTL, timeout
 * - llm: Provider, model, context/max tokens, timeout
 * - vector_store: Driver selection and index parameters
 * - search: Mode (hybrid/vector), top K, similarity threshold, query expansion, MMR
 * - chunking: Chunk size and overlap for document processing
 * - chat: Max question length and messages per session
 * - pagination: Per-page limits for API responses
 * - logging: Log channel for RAG-specific logging
 *
 * @see AiModel For per-model overrides in the database
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Embedding Configuration
    |--------------------------------------------------------------------------
    */
    'embedding' => [
        'provider' => env('RAG_EMBEDDING_PROVIDER', 'ollama'),
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('RAG_EMBEDDING_BASE_URL', 'http://localhost:11434'),
        'model' => env('RAG_EMBEDDING_MODEL', 'nomic-embed-text:latest'),
        'dimensions' => (int) env('RAG_EMBEDDING_DIMENSIONS', 768),
        'batch_size' => (int) env('RAG_EMBEDDING_BATCH_SIZE', 100),
        'cache_ttl' => (int) env('RAG_EMBEDDING_CACHE_TTL', 86400),
        'timeout' => (int) env('RAG_EMBEDDING_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | LLM Configuration
    |--------------------------------------------------------------------------
    */
    'llm' => [
        'provider' => env('RAG_LLM_PROVIDER', 'ollama'),
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('RAG_LLM_BASE_URL', 'http://localhost:11434'),
        'model' => env('RAG_LLM_MODEL', 'qwen3.5:9b'),
        'max_context_tokens' => (int) env('RAG_LLM_MAX_CONTEXT_TOKENS', 32768),
        'max_tokens' => (int) env('RAG_LLM_MAX_TOKENS', 4096),
        'timeout' => (int) env('RAG_LLM_TIMEOUT', 120),

        // Provider-specific API keys (used by ProviderFactory fallback)
        'gemini_api_key' => env('GEMINI_API_KEY'),
        'claude_api_key' => env('CLAUDE_API_KEY'),
        'deepseek_api_key' => env('DEEPSEEK_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Vector Store Configuration
    |--------------------------------------------------------------------------
    */
    'vector_store' => [
        'driver' => env('RAG_VECTOR_DRIVER', 'pgsql'),
        'index_lists' => (int) env('RAG_VECTOR_INDEX_LISTS', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Configuration
    |--------------------------------------------------------------------------
    */
    'search' => [
        'mode' => env('RAG_SEARCH_MODE', 'hybrid'),
        'top_k' => (int) env('RAG_SEARCH_TOP_K', 5),
        'similarity_threshold' => (float) env('RAG_SEARCH_SIMILARITY_THRESHOLD', 0.65),
        'query_expansion' => [
            'enabled' => (bool) env('RAG_QUERY_EXPANSION_ENABLED', false),
            'num_queries' => (int) env('RAG_QUERY_EXPANSION_NUM_QUERIES', 3),
        ],
        'mmr' => [
            'enabled' => (bool) env('RAG_SEARCH_MMR_ENABLED', true),
            'lambda' => (float) env('RAG_SEARCH_MMR_LAMBDA', 0.7),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Chunking Configuration
    |--------------------------------------------------------------------------
    */
    'chunking' => [
        'chunk_size' => (int) env('RAG_CHUNK_SIZE', 1000),
        'overlap' => (int) env('RAG_CHUNK_OVERLAP', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat Configuration
    |--------------------------------------------------------------------------
    */
    'chat' => [
        'max_question_length' => (int) env('RAG_MAX_QUESTION_LENGTH', 1000),
        'max_messages_per_session' => (int) env('RAG_MAX_MESSAGES_PER_SESSION', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination Configuration
    |--------------------------------------------------------------------------
    */
    'pagination' => [
        'per_page' => (int) env('RAG_PAGINATION_PER_PAGE', 20),
        'max_per_page' => (int) env('RAG_PAGINATION_MAX_PER_PAGE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'channel' => env('RAG_LOG_CHANNEL', 'rag'),
    ],

];
