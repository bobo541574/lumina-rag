<?php

declare(strict_types=1);

namespace Modules\ChatModule\Services\Pipeline;

/**
 * Rewritten Query Data Transfer Object
 *
 * Carries the output of QueryRewriterService through the RAG pipeline.
 * Contains the cleaned embedding text for vector search, an optional
 * boolean FTS query for to_tsquery, the complexity mode label, and
 * the raw complexity score for debugging and monitoring.
 *
 * @param  string  $embeddingText  Cleaned text for vector embedding.
 *                                 Example: "2026-05-19 ရာသီဥတု မိုးလေဝသ ရန်ကုန်"
 * @param  string|null  $ftsQuery  Boolean FTS query for PostgreSQL to_tsquery.
 *                                 Example: "weather & (rain | climate) & yangon"
 * @param  string  $mode  Complexity mode: "simple" or "complex".
 *                        Example: "simple"
 * @param  int  $score  Raw complexity score.
 *                      Example: 0
 */
readonly class RewrittenQuery
{
    public function __construct(
        public string $embeddingText,
        public ?string $ftsQuery = null,
        public string $mode = 'simple',
        public int $score = 0,
    ) {}
}
