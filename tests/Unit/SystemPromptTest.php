<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Modules\ChatModule\Services\RAGPipelineService;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\EmbeddingModule\Services\ProviderFactory;
use Modules\LLMModule\Contracts\LLMServiceInterface;
use Modules\SettingsModule\Contracts\TermAliasServiceInterface;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

/**
 * Call the private buildSystemPrompt method on RAGPipelineService
 *
 * Uses reflection to invoke the private method and return the prompt string.
 *
 * @param  string  $confidence  "high" or "low". Example: "high"
 * @param  bool  $hasOldDocuments  Whether source documents are >1 year old. Example: false
 * @return string The generated system prompt. Example: "You are a precise document-answering assistant..."
 */
function callBuildSystemPrompt(string $confidence, bool $hasOldDocuments = false): string
{
    $embedder = mock(EmbeddingServiceInterface::class);
    $vectorStore = mock(VectorStoreInterface::class);
    $llm = mock(LLMServiceInterface::class);
    $providerFactory = mock(ProviderFactory::class);
    $cache = mock(CacheRepository::class);
    $termAliasService = mock(TermAliasServiceInterface::class);

    $service = new RAGPipelineService(
        $embedder, $vectorStore, $llm, $providerFactory, $cache, $termAliasService,
    );

    $reflection = new ReflectionMethod(RAGPipelineService::class, 'buildSystemPrompt');
    $reflection->setAccessible(true);

    return $reflection->invoke($service, $confidence, $hasOldDocuments);
}

test('high_confidence_prompt_contains_grounding_rules', function (): void {
    $prompt = callBuildSystemPrompt('high');

    expect($prompt)->toContain('Answer ONLY using the provided context');
    expect($prompt)->toContain('NEVER make up');
    expect($prompt)->toContain('Cite the source document title');
});

test('high_confidence_prompt_contains_language_matching_rule', function (): void {
    $prompt = callBuildSystemPrompt('high');

    expect($prompt)->toContain('SAME LANGUAGE');
    expect($prompt)->toContain('Burmese');
    expect($prompt)->toContain('English');
});

test('high_confidence_prompt_contains_metadata_awareness', function (): void {
    $prompt = callBuildSystemPrompt('high');

    expect($prompt)->toContain('Report by');
    expect($prompt)->toContain('Project');
    expect($prompt)->toContain('Date');
});

test('high_confidence_prompt_contains_behavior_rules', function (): void {
    $prompt = callBuildSystemPrompt('high');

    expect($prompt)->toContain('Tone');
    expect($prompt)->toContain('Formatting');
    expect($prompt)->toContain('Markdown');
    expect($prompt)->toContain('Conciseness');
});

test('low_confidence_prompt_contains_uncertainty_guidance', function (): void {
    $prompt = callBuildSystemPrompt('low');

    expect($prompt)->toContain('LOW CONFIDENCE');
    expect($prompt)->toContain('limited');
    expect($prompt)->toContain('Based on limited available information');
    expect($prompt)->toContain('suggest what additional information');
});

test('low_confidence_prompt_still_contains_grounding_rules', function (): void {
    $prompt = callBuildSystemPrompt('low');

    expect($prompt)->toContain('Answer ONLY using the provided context');
    expect($prompt)->toContain('NEVER make up');
});

test('old_documents_prompt_contains_time_sensitivity_note', function (): void {
    $prompt = callBuildSystemPrompt('high', true);

    expect($prompt)->toContain('OLD DOCUMENTS');
    expect($prompt)->toContain('over a year old');
    expect($prompt)->toContain('According to a document from');
});

test('low_confidence_and_old_documents_prompts_both_sections', function (): void {
    $prompt = callBuildSystemPrompt('low', true);

    expect($prompt)->toContain('LOW CONFIDENCE');
    expect($prompt)->toContain('OLD DOCUMENTS');
});

test('prompt_contains_no_hallucination_rule', function (): void {
    $prompt = callBuildSystemPrompt('high');

    expect($prompt)->toContain('Fabrication');
    expect($prompt)->toContain('strictly forbidden');
});

test('prompt_contains_citation_format', function (): void {
    $prompt = callBuildSystemPrompt('high');

    expect($prompt)->toContain('[Source:');
    expect($prompt)->toContain('Document Title');
});

test('prompt_does_not_contain_emojis', function (): void {
    $prompt = callBuildSystemPrompt('high');

    expect($prompt)->not->toContain('😊');
    expect($prompt)->not->toContain('👍');
});
