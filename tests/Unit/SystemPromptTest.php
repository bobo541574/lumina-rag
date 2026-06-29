<?php

declare(strict_types=1);

use Modules\ChatModule\Services\Pipeline\ResponseBuilder;
use Modules\ChatModule\Services\Pipeline\SessionManager;

/**
 * Call buildSystemPrompt on ResponseBuilder
 *
 * Instantiates ResponseBuilder with a mock SessionManager and
 * invokes buildSystemPrompt directly.
 *
 * @param  string  $confidence  "high" or "low". Example: "high"
 * @param  bool  $hasOldDocuments  Whether source documents are >1 year old. Example: false
 * @return string The generated system prompt. Example: "You are a precise document-answering assistant..."
 */
function callBuildSystemPrompt(string $confidence, bool $hasOldDocuments = false): string
{
    $sessionManager = mock(SessionManager::class);
    $responseBuilder = new ResponseBuilder($sessionManager);

    return $responseBuilder->buildSystemPrompt($confidence, $hasOldDocuments);
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
    expect($prompt)->toContain('Completeness Over Brevity');
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
