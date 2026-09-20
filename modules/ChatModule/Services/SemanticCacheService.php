<?php

declare(strict_types=1);

namespace Modules\ChatModule\Services;

use Illuminate\Support\Facades\DB;
use Modules\ChatModule\Models\DocumentVersion;
use Modules\ChatModule\Models\KnowledgeVersion;
use Modules\ChatModule\Models\RagAnswerCache;

/**
 * Semantic Cache Service
 *
 * Provides answer-level caching to short-circuit expensive retrieval + LLM
 * generation for semantically-equivalent questions.
 *
 * Design decisions:
 * - The knowledge version (durable DB counter) is part of the cache key so every
 *   document lifecycle event (upload completed, delete, metadata update, re-embed)
 *   invalidates the whole corpus consistently — no stale answers can survive.
 * - Cache entries are stored in a persistent DB table (not the volatile cache
 *   store) so entries survive cache/Redis flushes and remain consistent with the
 *   durable version counter.
 * - Hit detection is semantic: the incoming query vector is compared via cosine
 *   similarity against stored query vectors above a configurable threshold
 *   (default 0.95). This matches paraphrases without exact-text equality.
 * - Entries are scoped by user_id and the LLM configuration hash to avoid
 *   cross-tenant / cross-model leakage.
 *
 * The service lets readers opt out for requests that vary per-call (context
 * history, filters), which the pipeline passes via the $options key
 * `skip_cache` when it cannot be safely cached.
 */
class SemanticCacheService
{
    private float $similarityThreshold;

    private int $cacheTtlDays;

    public function __construct()
    {
        $this->similarityThreshold = (float) config('rag.cache.semantic_threshold', 0.95);
        $this->cacheTtlDays = (int) config('rag.cache.ttl_days', 7);
    }

    /**
     * Attempt to retrieve a cached answer for a semantically-similar question.
     *
     * Loads all cache entries for the current knowledge version + user + model,
     * computes cosine similarity between the incoming query vector and each
     * stored query vector, and returns the best matching entry when it exceeds
     * the threshold. Returns null on miss (or when caching is disabled).
     *
     * @param  array  $queryVector  Query embedding vector. Example: [0.012, -0.034, ..., 0.098]
     * @param  string|null  $userId  Owning user ULID (null = public/global). Example: "01J..."
     * @param  string  $modelHash  Hash of the active LLM configuration. Example: md5("gpt-4o:0.3:4096")
     * @param  bool  $enabled  Whether semantic caching is enabled. Example: true
     * @return null|array{question: string, answer: string, sources: array, is_refusal: bool} Best matching cached answer payload, or null. Example: ["question" => "...", "answer" => "...", "sources" => [], "is_refusal" => false]
     */
    public function get(array $queryVector, ?string $userId, string $modelHash, bool $enabled = true): ?array
    {
        if (! $enabled || $queryVector === []) {
            return null;
        }

        $version = KnowledgeVersion::current();
        $start = microtime(true);

        $rows = RagAnswerCache::query()
            ->where('version', $version)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->where('model_hash', $modelHash)
            ->orderBy('created_at', 'desc')
            ->limit(200)
            ->get(['query_vector', 'question', 'answer', 'sources', 'document_ids', 'doc_versions', 'is_refusal']);

        $best = null;
        $bestScore = 0.0;

        foreach ($rows as $row) {
            $storedVector = json_decode((string) $row->query_vector, true);
            if (! is_array($storedVector) || $storedVector === []) {
                continue;
            }

            // Fine-grained (Phase 2): drop entries whose source documents have
            // changed since the answer was generated.
            if ($this->isStale($row)) {
                $row->delete();

                continue;
            }

            $score = $this->cosineSimilarity($queryVector, $storedVector);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        if ($best === null || $bestScore < $this->similarityThreshold) {
            return null;
        }

        if (config('logging.channels.rag', false)) {
            logger()->channel('rag')->debug('Semantic cache hit', [
                'score' => round($bestScore, 4),
                'version' => $version,
                'lookup_ms' => round((microtime(true) - $start) * 1000, 2),
            ]);
        }

        return [
            'question' => (string) $best->question,
            'answer' => (string) $best->answer,
            'sources' => $best->sources ?? [],
            'is_refusal' => (bool) $best->is_refusal,
        ];
    }

    /**
     * Persist a generated answer into the semantic cache.
     *
     * Stores the query vector, question, answer, sources, the current knowledge
     * version, user scope, model hash, and the set of source document IDs (used
     * for optional fine-grained invalidation).
     *
     * @param  array  $queryVector  Query embedding vector. Example: [0.012, -0.034, ..., 0.098]
     * @param  string  $question  The normalised question. Example: "What is Q3 revenue?"
     * @param  string  $answer  The generated answer text. Example: "Revenue was $45.2M."
     * @param  array  $sources  Source citations. Example: [["document_id" => "01J..."]]
     * @param  string|null  $userId  Owning user ULID. Example: "01J..."
     * @param  string  $modelHash  LLM configuration hash. Example: md5("gpt-4o")
     * @param  bool  $isRefusal  Whether this was a no-chunks refusal. Example: false
     * @param  bool  $enabled  Whether semantic caching is enabled. Example: true
     */
    public function put(
        array $queryVector,
        string $question,
        string $answer,
        array $sources,
        ?string $userId,
        string $modelHash,
        bool $isRefusal = false,
        bool $enabled = true,
    ): void {
        if (! $enabled || $queryVector === []) {
            return;
        }

        $documentIds = array_values(array_unique(array_filter(array_map(
            static fn (array $s): ?string => isset($s['document_id']) ? (string) $s['document_id'] : null,
            $sources,
        ))));

        // Fine-grained (Phase 2): snapshot the per-document versions so a later
        // lookup can detect whether any source document changed since this answer
        // was produced, without invalidating the whole corpus.
        $docVersions = [];
        foreach ($documentIds as $id) {
            $docVersions[$id] = DocumentVersion::current((string) $id);
        }

        RagAnswerCache::create([
            'version' => KnowledgeVersion::current(),
            'user_id' => $userId,
            'query_vector' => json_encode($queryVector),
            'question' => $question,
            'answer' => $answer,
            'sources' => $sources,
            'model_hash' => $modelHash,
            'document_ids' => $documentIds,
            'doc_versions' => $docVersions,
            'is_refusal' => $isRefusal,
        ]);

        $this->prune($userId);
    }

    /**
     * Build a stable model config hash.
     *
     * Combines the LLM provider/model with temperature and max tokens so that a
     * configuration change produces a different cache namespace.
     *
     * @param  string  $model  Model identifier. Example: "gpt-4o"
     * @param  float  $temperature  Sampled temperature. Example: 0.3
     * @param  int  $maxTokens  Max generation tokens. Example: 4096
     * @return string MD5 hash combining model config. Example: "a1b2c3d4e5f6..."
     */
    public static function modelHash(string $model, float $temperature, int $maxTokens): string
    {
        return md5("{$model}|{$temperature}|{$maxTokens}");
    }

    /**
     * Remove entries that exceed the TTL window for a given user scope.
     *
     * Keeps the cache table bounded by deleting rows older than the configured
     * TTL. Called after each write.
     *
     * @param  string|null  $userId  Owning user ULID. Example: "01J..."
     */
    private function prune(?string $userId): void
    {
        try {
            $cutoff = now()->subDays($this->cacheTtlDays);
            RagAnswerCache::query()
                ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
                ->where('created_at', '<', $cutoff)
                ->delete();
        } catch (\Throwable) {
            // Best-effort cleanup; never break the cache write path.
        }
    }

    /**
     * Invalidate all cached answers.
     *
     * Truncates the semantic cache table entirely. Called when fine-grained
     * invalidation is not required (e.g. bulk re-embed), or as a safety net.
     */
    public function flush(): void
    {
        try {
            DB::table('rag_answer_caches')->truncate();
        } catch (\Throwable) {
            RagAnswerCache::query()->delete();
        }
    }

    /**
     * Invalidate cached answers that reference a specific set of documents.
     *
     * @param  array  $documentIds  Document ULIDs to invalidate. Example: ["01J...", "01K..."]
     */
    public function invalidateForDocuments(array $documentIds): void
    {
        if ($documentIds === []) {
            return;
        }

        $cache = RagAnswerCache::all(['id', 'document_ids']);

        foreach ($cache as $entry) {
            $ids = $entry->document_ids ?? [];
            if (array_intersect($ids, $documentIds) !== []) {
                $entry->delete();
            }
        }
    }

    /**
     * Determine whether a cache entry is stale because one of its source
     * documents changed since the answer was generated.
     *
     * Compares the document-version snapshot stored on the entry against the
     * current per-document versions. Entries without a snapshot that reference
     * documents are treated as stale for safety; entries referencing no
     * documents cannot be fine-grained invalidated and rely on the global
     * version instead.
     *
     * @param  RagAnswerCache  $entry  Cache row to inspect. Example: a RagAnswerCache
     * @return bool True when the entry must be discarded. Example: true
     */
    private function isStale(RagAnswerCache $entry): bool
    {
        $documents = $entry->document_ids ?? [];
        $snapshot = $entry->doc_versions ?? [];

        // No source documents → only the global version governs validity.
        if ($documents === []) {
            return false;
        }

        // Referenced documents but no snapshot → written pre-Phase-2, unknown.
        foreach ($documents as $id) {
            $sid = (string) $id;
            if (! isset($snapshot[$sid])) {
                return true;
            }

            if ((int) $snapshot[$sid] !== DocumentVersion::current($sid)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compute cosine similarity between two vectors.
     *
     * @param  array  $a  First vector. Example: [0.1, 0.2]
     * @param  array  $b  Second vector. Example: [0.4, 0.5]
     * @return float Similarity between -1 and 1. Example: 0.98
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $len = min(count($a), count($b));

        for ($i = 0; $i < $len; $i++) {
            $dot += (float) $a[$i] * (float) $b[$i];
            $normA += (float) $a[$i] * (float) $a[$i];
            $normB += (float) $b[$i] * (float) $b[$i];
        }

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
