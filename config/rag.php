<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Embedding Configuration
    |--------------------------------------------------------------------------
    */
    'embedding' => [
        'provider' => env('RAG_EMBEDDING_PROVIDER', 'openai'),
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('RAG_EMBEDDING_BASE_URL', 'http://localhost:11434'),
        'model' => env('RAG_EMBEDDING_MODEL', 'text-embedding-ada-002'),
        'dimensions' => (int) env('RAG_EMBEDDING_DIMENSIONS', 1536),
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
        'provider' => env('RAG_LLM_PROVIDER', 'openai'),
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('RAG_LLM_BASE_URL', 'http://localhost:11434'),
        'model' => env('RAG_LLM_MODEL', 'gpt-4o'),
        'temperature' => (float) env('RAG_LLM_TEMPERATURE', 0.3),
        'max_context_tokens' => (int) env('RAG_LLM_MAX_CONTEXT_TOKENS', 4000),
        'timeout' => (int) env('RAG_LLM_TIMEOUT', 60),
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
        'top_k' => (int) env('RAG_SEARCH_TOP_K', 5),
        'similarity_threshold' => (float) env('RAG_SEARCH_SIMILARITY_THRESHOLD', 0.65),
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

];
