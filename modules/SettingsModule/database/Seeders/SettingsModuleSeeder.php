<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\SettingsModule\Models\AiModel;

class SettingsModuleSeeder extends Seeder
{
    public function run(): void
    {
        $embeddingModels = [
            [
                'name' => 'nomic-embed-text',
                'type' => 'embedding',
                'provider' => 'ollama',
                'model' => 'nomic-embed-text:latest',
                'base_url' => 'http://localhost:11434',
                'dimensions' => 768,
                'batch_size' => 100,
                'cache_ttl' => 86400,
                'timeout' => 30,
                'is_active' => true,
                'sort_order' => 1,
                'description' => 'Fast general-purpose embedding (768d). Good balance of speed and quality for most documents.',
            ],
            [
                'name' => 'mxbai-embed-large',
                'type' => 'embedding',
                'provider' => 'ollama',
                'model' => 'mxbai-embed-large:latest',
                'base_url' => 'http://localhost:11434',
                'dimensions' => 1024,
                'batch_size' => 100,
                'cache_ttl' => 86400,
                'timeout' => 30,
                'is_active' => true,
                'sort_order' => 2,
                'description' => 'High-quality embedding (1024d). Better accuracy than nomic for nuanced retrieval.',
            ],
            [
                'name' => 'all-MiniLM-L6-v2',
                'type' => 'embedding',
                'provider' => 'ollama',
                'model' => 'mahonzhan/all-MiniLM-L6-v2:latest',
                'base_url' => 'http://localhost:11434',
                'dimensions' => 384,
                'batch_size' => 100,
                'cache_ttl' => 86400,
                'timeout' => 30,
                'is_active' => true,
                'sort_order' => 3,
                'description' => 'Lightweight embedding (384d). Fastest option, good for high-throughput or simple queries.',
            ],
        ];

        $llmModels = [
            [
                'name' => 'Qwen 3.5 (9B)',
                'type' => 'llm',
                'provider' => 'ollama',
                'model' => 'qwen3.5:9b',
                'base_url' => 'http://localhost:11434',
                'temperature' => 0.3,
                'max_context_tokens' => 32768,
                'timeout' => 120,
                'is_active' => true,
                'sort_order' => 1,
                'description' => 'Strong general-purpose 9B model with 32K context. Good reasoning and instruction following.',
                'settings' => ['max_tokens' => 4096],
            ],
            [
                'name' => 'Qwen 3.5 Claude Opus (9B)',
                'type' => 'llm',
                'provider' => 'ollama',
                'model' => 'sorc/qwen3.5-claude-4.6-opus:9b',
                'base_url' => 'http://localhost:11434',
                'temperature' => 0.3,
                'max_context_tokens' => 32768,
                'timeout' => 120,
                'is_active' => true,
                'sort_order' => 2,
                'description' => 'Qwen 3.5 finetuned with Claude-style responses. Creative and detailed answers.',
                'settings' => ['max_tokens' => 4096],
            ],
            [
                'name' => 'Gemma 4 (e4b)',
                'type' => 'llm',
                'provider' => 'ollama',
                'model' => 'gemma4:e4b',
                'base_url' => 'http://localhost:11434',
                'temperature' => 0.3,
                'max_context_tokens' => 16384,
                'timeout' => 120,
                'is_active' => true,
                'sort_order' => 3,
                'description' => 'Google Gemma 4 — efficient 16K context. Great for concise Q&A and summarization.',
                'settings' => ['max_tokens' => 4096],
            ],
            [
                'name' => 'Qwen 2.5 Coder',
                'type' => 'llm',
                'provider' => 'ollama',
                'model' => 'qwen2.5-coder:latest',
                'base_url' => 'http://localhost:11434',
                'temperature' => 0.2,
                'max_context_tokens' => 32768,
                'timeout' => 120,
                'is_active' => true,
                'sort_order' => 4,
                'description' => 'Code-specialized 32K context model. Best for technical documents and code analysis.',
                'settings' => ['max_tokens' => 4096],
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
