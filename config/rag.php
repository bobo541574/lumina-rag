<?php

declare(strict_types=1);

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
        'timeout' => (int) env('RAG_LLM_TIMEOUT', 120),
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
    | Logging Configuration
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'channel' => env('RAG_LOG_CHANNEL', 'rag'),
    ],

];
