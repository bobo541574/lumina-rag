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
        'temperature' => (float) env('RAG_LLM_TEMPERATURE', 0.3),
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
        // Floor passed to the vector store on every search; the elbow method
        // then finds the natural cutoff above this value.
        'min_initial_threshold' => (float) env('RAG_SEARCH_MIN_INITIAL_THRESHOLD', 0.20),
        // Reciprocal Rank Fusion rank constant (k in 1/(k+rank)).
        'rrf_k' => (int) env('RAG_SEARCH_RRF_K', 60),
        'threshold' => [
            // Minimum gap between consecutive scores to trigger the elbow cut.
            'gap_significance' => (float) env('RAG_THRESHOLD_GAP_SIGNIFICANCE', 0.15),
            // Base threshold used when user/project/date filters narrow the search.
            'filtered_base' => (float) env('RAG_THRESHOLD_FILTERED_BASE', 0.45),
            // Scales the top score when no significant gap is found.
            'score_scaling' => (float) env('RAG_THRESHOLD_SCORE_SCALING', 0.85),
            // Minimum top-score required to return a single fallback chunk.
            'fallback_min' => (float) env('RAG_THRESHOLD_FALLBACK_MIN', 0.25),
        ],
        'query_expansion' => [
            'enabled' => (bool) env('RAG_QUERY_EXPANSION_ENABLED', false),
            'num_queries' => (int) env('RAG_QUERY_EXPANSION_NUM_QUERIES', 3),
        ],
        'query_rewriter' => [
            'enabled' => (bool) env('RAG_QUERY_REWRITER_ENABLED', true),
            'complexity_threshold' => (int) env('RAG_QUERY_REWRITER_COMPLEXITY_THRESHOLD', 5),
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
        // Hours of inactivity before a session is considered expired.
        'session_timeout_hours' => (int) env('RAG_SESSION_TIMEOUT_HOURS', 24),
        // Number of prior message pairs injected as conversation history for LLM context.
        'history_window' => (int) env('RAG_CHAT_HISTORY_WINDOW', 4),
        // Per-user request ceiling for the chat endpoint (per minute) to bound
        // provider spend and worker load (ISO 27002:8.6 capacity management).
        'max_requests_per_minute' => (int) env('RAG_CHAT_MAX_REQUESTS_PER_MINUTE', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    */
    'security' => [
        // Scope vector retrieval to the authenticated user's own documents.
        // Disable only for an explicitly shared (org-wide) corpus.
        'tenant_isolation' => (bool) env('RAG_TENANT_ISOLATION', true),
        // Lifetime of an API token in days before expiry / sliding renewal.
        'api_token_ttl_days' => (int) env('RAG_API_TOKEN_TTL_DAYS', 30),
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
    | Semantic Cache Configuration
    |--------------------------------------------------------------------------
    */
    'cache' => [
        // Master switch for answer-level (semantic) caching.
        'enabled' => (bool) env('RAG_CACHE_ENABLED', true),
        // Cosine-similarity threshold for a semantic cache hit.
        'semantic_threshold' => (float) env('RAG_CACHE_SEMANTIC_THRESHOLD', 0.95),
        // How long entries live before being pruned (in days).
        'ttl_days' => (int) env('RAG_CACHE_TTL_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reranker Configuration
    |--------------------------------------------------------------------------
    */
    'reranker' => [
        'enabled' => (bool) env('RAG_RERANKER_ENABLED', false),
        // Cross-encoder model served via the local Ollama-compatible endpoint.
        'model' => env('RAG_RERANKER_MODEL', 'bge-reranker:latest'),
        // How many top retrieval candidates are sent to the cross-encoder.
        'candidate_k' => (int) env('RAG_RERANKER_CANDIDATE_K', 8),
        // How many candidates survive reranking for the final context.
        'final_k' => (int) env('RAG_RERANKER_FINAL_K', 5),
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
