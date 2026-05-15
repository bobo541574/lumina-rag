<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\SettingsModule\Models\AiModel;
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

        // Seed default AI models
        $embeddingModels = [
            [
                'name' => 'OpenAI text-embedding-3-small',
                'type' => 'embedding',
                'provider' => 'openai',
                'model' => 'text-embedding-3-small',
                'dimensions' => 1536,
                'batch_size' => 100,
                'cache_ttl' => 86400,
                'timeout' => 30,
                'is_active' => true,
                'sort_order' => 1,
                'description' => 'Best price/quality balance. Fast and reliable with 1536d vectors. Requires OpenAI API key.',
            ],
            [
                'name' => 'OpenAI text-embedding-3-large',
                'type' => 'embedding',
                'provider' => 'openai',
                'model' => 'text-embedding-3-large',
                'dimensions' => 3072,
                'batch_size' => 100,
                'cache_ttl' => 86400,
                'timeout' => 30,
                'is_active' => true,
                'sort_order' => 2,
                'description' => 'Highest accuracy (3072d) for nuanced search, but more expensive and slower than small.',
            ],
            [
                'name' => 'Ollama nomic-embed-text',
                'type' => 'embedding',
                'provider' => 'ollama',
                'model' => 'nomic-embed-text',
                'base_url' => 'http://localhost:11434',
                'dimensions' => 768,
                'batch_size' => 100,
                'cache_ttl' => 86400,
                'timeout' => 30,
                'is_active' => false,
                'sort_order' => 3,
                'description' => 'Free and local-only (768d). No API key needed but requires local Ollama server.',
            ],
        ];

        $llmModels = [
            [
                'name' => 'OpenAI GPT-4o',
                'type' => 'llm',
                'provider' => 'openai',
                'model' => 'gpt-4o',
                'temperature' => 0.3,
                'max_context_tokens' => 128000,
                'timeout' => 60,
                'is_active' => true,
                'sort_order' => 1,
                'description' => 'Best-in-class reasoning with 128K context. Strong at complex RAG but most expensive.',
            ],
            [
                'name' => 'OpenAI GPT-4o-mini',
                'type' => 'llm',
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'temperature' => 0.3,
                'max_context_tokens' => 128000,
                'timeout' => 60,
                'is_active' => true,
                'sort_order' => 2,
                'description' => 'Fast and cheap with 128K context. Great for Q&A but lower reasoning than GPT-4o.',
            ],
            [
                'name' => 'Ollama Llama 3.2',
                'type' => 'llm',
                'provider' => 'ollama',
                'model' => 'llama3.2',
                'base_url' => 'http://localhost:11434',
                'temperature' => 0.3,
                'max_context_tokens' => 4096,
                'timeout' => 120,
                'is_active' => false,
                'sort_order' => 3,
                'description' => 'Free and local-only with 4K context. Good for offline dev but needs Ollama server.',
            ],
        ];

        foreach ($embeddingModels as $model) {
            AiModel::firstOrCreate(
                ['type' => 'embedding', 'model' => $model['model']],
                $model,
            );
        }

        foreach ($llmModels as $model) {
            AiModel::firstOrCreate(
                ['type' => 'llm', 'model' => $model['model']],
                $model,
            );
        }
    }
}
