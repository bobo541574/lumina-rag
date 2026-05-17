<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\SettingsModule\Models\AiModel;
use Modules\SettingsModule\Models\TermAlias;

/**
 * Settings Module Seeder
 * 
 * Seeds the database with default AI model configurations and term alias
 * mappings required for the RAG pipeline to function out of the box.
 * Populates embedding models (nomic, mxbai, MiniLM), LLM models (Qwen,
 * Gemma, Qwen Coder), and a curated set of Burmese→English term aliases.
 * 
 * Uses firstOrCreate to be idempotent — safe to run multiple times without
 * creating duplicate entries. The seeder is organized into embedding model
 * seeding, LLM model seeding, and term alias seeding.
 */
class SettingsModuleSeeder extends Seeder
{
    /**
     * Run the database seeders
     * 
     * Seeds embedding models (3 defaults), LLM models (4 defaults), and
     * term aliases (18 mappings covering project names, technical terms,
     * and abbreviations). Each seed uses firstOrCreate to ensure idempotency.
     *
     * @return void
     */
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

        $this->seedTermAliases();
    }

    /**
     * Seed term alias mappings
     * 
     * Inserts a curated set of Burmese→English term aliases for project names
     * (Orion, Nova, Apex, etc.), technical terms (CNN, API, database, etc.),
     * and general abbreviations (OR→Orion, NV→Nova).
     * Each entry uses firstOrCreate keyed by (alias, canonical) for idempotency.
     *
     * @return void
     */
    private function seedTermAliases(): void
    {
        $aliases = [
            ['type' => 'project', 'alias' => 'အိုရီယွန်', 'canonical' => 'Orion', 'description' => 'Project name: Orion in Burmese'],
            ['type' => 'project', 'alias' => 'အော်ရီယွန်', 'canonical' => 'Orion', 'description' => 'Project name: Orion alternative spelling'],
            ['type' => 'project', 'alias' => 'နိုဗာ', 'canonical' => 'Nova', 'description' => 'Project name: Nova in Burmese'],
            ['type' => 'project', 'alias' => 'အေပက်စ်', 'canonical' => 'Apex', 'description' => 'Project name: Apex in Burmese'],
            ['type' => 'project', 'alias' => 'ဟီလိယပ်', 'canonical' => 'Helios', 'description' => 'Project name: Helios in Burmese'],
            ['type' => 'project', 'alias' => 'အက်တလပ်', 'canonical' => 'Atlas', 'description' => 'Project name: Atlas in Burmese'],
            ['type' => 'project', 'alias' => 'ဖျူးရှင်း', 'canonical' => 'Fusion', 'description' => 'Project name: Fusion in Burmese'],
            ['type' => 'project', 'alias' => 'ဇင်းနစ်', 'canonical' => 'Zenith', 'description' => 'Project name: Zenith in Burmese'],
            ['type' => 'project', 'alias' => 'ကွမ်တမ်', 'canonical' => 'Quantum', 'description' => 'Project name: Quantum in Burmese'],

            ['type' => 'technical', 'alias' => 'စီအဲန်အန်', 'canonical' => 'CNN', 'description' => 'Convolutional Neural Network'],
            ['type' => 'technical', 'alias' => 'အာရ်အဲန်အန်', 'canonical' => 'RNN', 'description' => 'Recurrent Neural Network'],
            ['type' => 'technical', 'alias' => 'အေပီအိုင်', 'canonical' => 'API', 'description' => 'Application Programming Interface'],
            ['type' => 'technical', 'alias' => 'ယူအိုင်', 'canonical' => 'UI', 'description' => 'User Interface'],
            ['type' => 'technical', 'alias' => 'ယူအက်စ်အို', 'canonical' => 'UX', 'description' => 'User Experience'],
            ['type' => 'technical', 'alias' => 'ဒေတာဘေ့စ်', 'canonical' => 'database', 'description' => 'Database in Burmese'],
            ['type' => 'technical', 'alias' => 'ဆာဗာ', 'canonical' => 'server', 'description' => 'Server in Burmese'],

            ['type' => 'general', 'alias' => 'OR', 'canonical' => 'Orion', 'description' => 'Project Orion abbreviation'],
            ['type' => 'general', 'alias' => 'NV', 'canonical' => 'Nova', 'description' => 'Project Nova abbreviation'],
        ];

        foreach ($aliases as $alias) {
            TermAlias::firstOrCreate(
                ['alias' => $alias['alias'], 'canonical' => $alias['canonical']],
                $alias,
            );
        }
    }
}
