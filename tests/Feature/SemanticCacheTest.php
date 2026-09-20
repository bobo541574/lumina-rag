<?php

declare(strict_types=1);

use Modules\ChatModule\Events\KnowledgeChanged;
use Modules\ChatModule\Models\DocumentVersion;
use Modules\ChatModule\Models\KnowledgeVersion;
use Modules\ChatModule\Services\QuestionClassifier;
use Modules\ChatModule\Services\SemanticCacheService;

/**
 * Knowledge version starts at 1 and bumps monotonically.
 *
 * @return void
 */
test('knowledge_version_bumps_monotonically', function (): void {
    expect(KnowledgeVersion::current())->toBeInt();
    $first = KnowledgeVersion::current();
    KnowledgeVersion::bump();
    expect(KnowledgeVersion::current())->toBe($first + 1);
});

/**
 * Semantic cache stores and retrieves a semantically-equivalent question.
 *
 * Two questions that are paraphrases are embedded (with near-identical vectors)
 * and the second should hit the cache stored for the first.
 *
 * @return void
 */
test('semantic_cache_hits_similar_questions', function (): void {
    $service = new SemanticCacheService;
    $userId = null;
    $modelHash = SemanticCacheService::modelHash('gpt-4o', 0.3, 4096);

    // Store an answer for the first question.
    $v1 = [0.1, 0.2, 0.3, 0.4];
    $service->put($v1, 'What is Q3 revenue?', 'Revenue is $45M.', [], $userId, $modelHash);

    // A near-identical query vector should hit.
    $v2 = [0.1, 0.2, 0.3, 0.41];
    $hit = $service->get($v2, $userId, $modelHash, true);

    expect($hit)->not->toBeNull();
    expect($hit['answer'])->toBe('Revenue is $45M.');
});

/**
 * Semantic cache returns null below the similarity threshold.
 *
 * @return void
 */
test('semantic_cache_misses_dissimilar_questions', function (): void {
    $service = new SemanticCacheService;
    $userId = null;
    $modelHash = SemanticCacheService::modelHash('gpt-4o', 0.3, 4096);

    $service->put([0.1, 0.2, 0.3, 0.4], 'About finances', 'Answer A', [], $userId, $modelHash);

    // A very different vector should not match.
    $miss = $service->get([0.9, 0.8, 0.7, 0.6], $userId, $modelHash, true);

    expect($miss)->toBeNull();
});

/**
 * Question classifier detects question types.
 *
 * @return void
 */
test('question_classifier_detects_types', function (): void {
    $classifier = new QuestionClassifier;

    expect($classifier->classify('Compare Q3 and Q4 revenue')['type'])->toBe('comparative');
    expect($classifier->classify('Explain why revenue fell')['type'])->toBe('analytical');
    expect($classifier->classify('Who filed the report?')['type'])->toBe('factual');
    expect($classifier->classify('Tell me about the documents')['type'])->toBe('generic');
});

/**
 * Dispatch with a specific document bumps the document version, not the global.
 *
 * Phase 2 fine-grained invalidation: naming documents only invalidates those
 * documents, leaving the coarse global knowledge version untouched so unrelated
 * cached answers remain valid.
 *
 * @return void
 */
test('knowledge_changed_with_document_bumps_document_version_only', function (): void {
    $beforeGlobal = KnowledgeVersion::current();
    $beforeDoc = DocumentVersion::current('01JAR');

    KnowledgeChanged::dispatch(['01JAR']);

    expect(KnowledgeVersion::current())->toBe($beforeGlobal);
    expect(DocumentVersion::current('01JAR'))->toBe($beforeDoc + 1);
});

/**
 * Dispatch with no documents bumps the coarse global knowledge version.
 *
 * An unattributed/knowledge-wide change (e.g. bulk re-embed) falls back to the
 * global version, which invalidates the whole corpus cache namespace.
 *
 * @return void
 */
test('knowledge_changed_without_document_bumps_global_version', function (): void {
    $beforeGlobal = KnowledgeVersion::current();

    KnowledgeChanged::dispatch([]);

    expect(KnowledgeVersion::current())->toBe($beforeGlobal + 1);
});

/**
 * A cached answer stays valid when only an unrelated document changes.
 *
 * Phase 2 fine-grained invalidation: an answer grounded in Doc A should still
 * hit after Doc B changes (and its document version is bumped), because the
 * source documents of the answer are unchanged.
 *
 * @return void
 */
test('cached_answer_survives_change_to_unrelated_document', function (): void {
    $service = new SemanticCacheService;
    $userId = null;
    $modelHash = SemanticCacheService::modelHash('gpt-4o', 0.3, 4096);

    $sources = [['document_id' => '01JARA']];
    $service->put([0.1, 0.2, 0.3, 0.4], 'Doc A question', 'Doc A answer', $sources, $userId, $modelHash);

    // Bump an unrelated document's version.
    KnowledgeChanged::dispatch(['01JARB']);

    $hit = $service->get([0.1, 0.2, 0.3, 0.41], $userId, $modelHash, true);

    expect($hit)->not->toBeNull();
    expect($hit['answer'])->toBe('Doc A answer');
});

/**
 * A cached answer is discarded when one of its source documents changes.
 *
 * Bumping the version of the answer's own source document must invalidate it on
 * the next lookup, forcing regeneration.
 *
 * @return void
 */
test('cached_answer_invalidated_when_source_document_changes', function (): void {
    $service = new SemanticCacheService;
    $userId = null;
    $modelHash = SemanticCacheService::modelHash('gpt-4o', 0.3, 4096);

    $sources = [['document_id' => '01JARA']];
    $service->put([0.1, 0.2, 0.3, 0.4], 'Doc A question', 'Doc A answer', $sources, $userId, $modelHash);

    // Bump the answer's own source document.
    KnowledgeChanged::dispatch(['01JARA']);

    $hit = $service->get([0.1, 0.2, 0.3, 0.41], $userId, $modelHash, true);

    expect($hit)->toBeNull();
});
