<?php

declare(strict_types=1);

namespace Modules\ChatModule\Services\Pipeline;

use Illuminate\Support\Facades\Log;
use Modules\ChatModule\Models\ChatSession;
use Modules\ChatModule\Services\SemanticCacheService;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Pipeline glue for the semantic answer cache.
 *
 * Cache failures never fail a chat request: they are logged and treated as
 * a miss (lookup) or skipped (store).
 */
class AnswerCache
{
    public function __construct(
        private SemanticCacheService $semanticCache,
        private bool $enabled = true,
    ) {}

    /**
     * Find a cached answer for a near-identical question.
     *
     * Skipped for follow-ups, whose answers depend on conversation history.
     *
     * @param  string  $searchQuestion  Rewritten search question. Example: "Q3 revenue"
     * @param  EmbeddingServiceInterface  $embedder  Embedder for the question vector
     * @param  ChatSession  $session  Session (user scope and history check)
     * @param  LlmProfile  $profile  Model answering the request (cache key)
     * @return array{answer: string, sources: array, question?: string}|null Cached payload or null on miss
     */
    public function lookup(string $searchQuestion, EmbeddingServiceInterface $embedder, ChatSession $session, LlmProfile $profile): ?array
    {
        if (! $this->enabled || $session->message_count > 1) {
            return null;
        }

        try {
            return $this->semanticCache->get($embedder->embed($searchQuestion), $session->user_id, $profile->cacheHash);
        } catch (\Throwable $e) {
            $this->log()->warning('Semantic cache lookup failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Store an answer (or refusal) for future lookups.
     *
     * @param  string  $searchQuestion  Rewritten search question. Example: "Q3 revenue"
     * @param  EmbeddingServiceInterface  $embedder  Embedder for the question vector
     * @param  LlmProfile  $profile  Model that produced the answer (cache key)
     * @param  string|null  $userId  Owning user. Example: "01J..."
     * @param  string  $question  Original question. Example: "What is Q3 revenue?"
     * @param  string  $answer  Answer text. Example: "Revenue was $45.2M."
     * @param  array  $sources  Source citations
     * @param  bool  $isRefusal  Whether the answer is a refusal. Example: false
     */
    public function store(
        string $searchQuestion,
        EmbeddingServiceInterface $embedder,
        LlmProfile $profile,
        ?string $userId,
        string $question,
        string $answer,
        array $sources,
        bool $isRefusal,
    ): void {
        if (! $this->enabled) {
            return;
        }

        try {
            $this->semanticCache->put($embedder->embed($searchQuestion), $question, $answer, $sources, $userId, $profile->cacheHash, $isRefusal);
        } catch (\Throwable $e) {
            $this->log()->warning('Semantic cache write failed', ['error' => $e->getMessage()]);
        }
    }

    private function log(): LoggerInterface
    {
        return Log::channel(config('rag.logging.channel', 'rag'));
    }
}
