<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\SettingsModule\Models\Setting;

class SettingsModuleSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['key' => 'RAG_EMBEDDING_PROVIDER', 'value' => env('RAG_EMBEDDING_PROVIDER', 'openai'), 'type' => 'string', 'group' => 'embedding'],
            ['key' => 'RAG_EMBEDDING_MODEL', 'value' => env('RAG_EMBEDDING_MODEL', 'text-embedding-3-small'), 'type' => 'string', 'group' => 'embedding'],
            ['key' => 'RAG_EMBEDDING_DIMENSIONS', 'value' => env('RAG_EMBEDDING_DIMENSIONS', '1536'), 'type' => 'int', 'group' => 'embedding'],
            ['key' => 'RAG_EMBEDDING_BATCH_SIZE', 'value' => env('RAG_EMBEDDING_BATCH_SIZE', '100'), 'type' => 'int', 'group' => 'embedding'],
            ['key' => 'RAG_EMBEDDING_CACHE_TTL', 'value' => env('RAG_EMBEDDING_CACHE_TTL', '86400'), 'type' => 'int', 'group' => 'embedding'],
            ['key' => 'RAG_EMBEDDING_TIMEOUT', 'value' => env('RAG_EMBEDDING_TIMEOUT', '30'), 'type' => 'int', 'group' => 'embedding'],
            ['key' => 'RAG_EMBEDDING_BASE_URL', 'value' => env('RAG_EMBEDDING_BASE_URL', 'http://localhost:11434'), 'type' => 'string', 'group' => 'embedding'],
            ['key' => 'RAG_LLM_PROVIDER', 'value' => env('RAG_LLM_PROVIDER', 'openai'), 'type' => 'string', 'group' => 'llm'],
            ['key' => 'RAG_LLM_MODEL', 'value' => env('RAG_LLM_MODEL', 'gpt-4o'), 'type' => 'string', 'group' => 'llm'],
            ['key' => 'RAG_LLM_TEMPERATURE', 'value' => env('RAG_LLM_TEMPERATURE', '0.3'), 'type' => 'float', 'group' => 'llm'],
            ['key' => 'RAG_LLM_MAX_CONTEXT_TOKENS', 'value' => env('RAG_LLM_MAX_CONTEXT_TOKENS', '4000'), 'type' => 'int', 'group' => 'llm'],
            ['key' => 'RAG_LLM_TIMEOUT', 'value' => env('RAG_LLM_TIMEOUT', '60'), 'type' => 'int', 'group' => 'llm'],
            ['key' => 'RAG_LLM_BASE_URL', 'value' => env('RAG_LLM_BASE_URL', 'http://localhost:11434'), 'type' => 'string', 'group' => 'llm'],
            ['key' => 'RAG_VECTOR_DRIVER', 'value' => env('RAG_VECTOR_DRIVER', 'pgsql'), 'type' => 'string', 'group' => 'vector_store'],
            ['key' => 'RAG_VECTOR_INDEX_LISTS', 'value' => env('RAG_VECTOR_INDEX_LISTS', '100'), 'type' => 'int', 'group' => 'vector_store'],
            ['key' => 'RAG_SEARCH_MODE', 'value' => env('RAG_SEARCH_MODE', 'hybrid'), 'type' => 'string', 'group' => 'search'],
            ['key' => 'RAG_SEARCH_TOP_K', 'value' => env('RAG_SEARCH_TOP_K', '5'), 'type' => 'int', 'group' => 'search'],
            ['key' => 'RAG_SEARCH_SIMILARITY_THRESHOLD', 'value' => env('RAG_SEARCH_SIMILARITY_THRESHOLD', '0.65'), 'type' => 'float', 'group' => 'search'],
            ['key' => 'RAG_SEARCH_HYBRID_VECTOR_WEIGHT', 'value' => env('RAG_SEARCH_HYBRID_VECTOR_WEIGHT', '0.7'), 'type' => 'float', 'group' => 'search'],
            ['key' => 'RAG_SEARCH_HYBRID_FTS_WEIGHT', 'value' => env('RAG_SEARCH_HYBRID_FTS_WEIGHT', '0.3'), 'type' => 'float', 'group' => 'search'],
            ['key' => 'RAG_QUERY_EXPANSION_ENABLED', 'value' => env('RAG_QUERY_EXPANSION_ENABLED', 'false'), 'type' => 'bool', 'group' => 'search'],
            ['key' => 'RAG_QUERY_EXPANSION_NUM_QUERIES', 'value' => env('RAG_QUERY_EXPANSION_NUM_QUERIES', '3'), 'type' => 'int', 'group' => 'search'],
            ['key' => 'RAG_SEARCH_MMR_ENABLED', 'value' => env('RAG_SEARCH_MMR_ENABLED', 'true'), 'type' => 'bool', 'group' => 'search'],
            ['key' => 'RAG_SEARCH_MMR_LAMBDA', 'value' => env('RAG_SEARCH_MMR_LAMBDA', '0.7'), 'type' => 'float', 'group' => 'search'],
            ['key' => 'RAG_CHUNK_SIZE', 'value' => env('RAG_CHUNK_SIZE', '1000'), 'type' => 'int', 'group' => 'chunking'],
            ['key' => 'RAG_CHUNK_OVERLAP', 'value' => env('RAG_CHUNK_OVERLAP', '200'), 'type' => 'int', 'group' => 'chunking'],
            ['key' => 'RAG_MAX_QUESTION_LENGTH', 'value' => env('RAG_MAX_QUESTION_LENGTH', '1000'), 'type' => 'int', 'group' => 'chat'],
            ['key' => 'RAG_MAX_MESSAGES_PER_SESSION', 'value' => env('RAG_MAX_MESSAGES_PER_SESSION', '100'), 'type' => 'int', 'group' => 'chat'],
            ['key' => 'RAG_EMBEDDING_AVAILABLE_MODELS', 'value' => '["text-embedding-3-small","text-embedding-3-large","text-embedding-ada-002"]', 'type' => 'json', 'group' => 'embedding'],
            ['key' => 'RAG_LLM_AVAILABLE_MODELS', 'value' => '["gpt-4o","gpt-4o-mini","gpt-4-turbo","llama3.2","gemma4:e4b"]', 'type' => 'json', 'group' => 'llm'],
            ['key' => 'RAG_LOG_CHANNEL', 'value' => env('RAG_LOG_CHANNEL', 'rag'), 'type' => 'string', 'group' => 'logging'],
            ['key' => 'RAG_LOG_LEVEL', 'value' => env('RAG_LOG_LEVEL', 'info'), 'type' => 'string', 'group' => 'logging'],
        ];

        foreach ($defaults as $entry) {
            Setting::firstOrCreate(
                ['key' => $entry['key']],
                $entry,
            );
        }
    }
}
