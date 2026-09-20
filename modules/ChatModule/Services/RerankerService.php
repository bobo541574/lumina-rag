<?php

declare(strict_types=1);

namespace Modules\ChatModule\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reranker Service
 *
 * Optional cross-encoder reranking stage placed after initial retrieval.
 * Unlike the bi-encoder used for retrieval, a cross-encoder takes the
 * question and each candidate chunk together and emits a single relevance
 * score, providing a higher-quality final ordering.
 *
 * Uses an Ollama-embedded reranker endpoint (e.g. bge-reranker) when enabled.
 * This stage is intentionally optional: when disabled the pipeline relies on
 * the existing cosine-threshold + MMR ordering.
 *
 * @param  bool  $enabled  Master switch. Example: false
 * @param  string  $baseUrl  Ollama-compatible base URL. Example: "http://localhost:11434"
 * @param  string  $model  Reranker model name. Example: "bge-reranker:latest"
 * @param  int  $candidateK  Candidates to rerank (from initial topN). Example: 8
 * @param  int  $finalK  Candidates to keep after reranking. Example: 5
 * @param  float  $timeout  Request timeout in seconds. Example: 30
 */
class RerankerService
{
    private bool $enabled;

    private string $baseUrl;

    private string $model;

    private int $candidateK;

    private int $finalK;

    private float $timeout;

    public function __construct(
        bool $enabled = false,
        string $baseUrl = 'http://localhost:11434',
        string $model = 'bge-reranker:latest',
        int $candidateK = 8,
        int $finalK = 5,
        float $timeout = 30,
    ) {
        $this->enabled = $enabled;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->model = $model;
        $this->candidateK = max(1, $candidateK);
        $this->finalK = max(1, $finalK);
        $this->timeout = $timeout;
    }

    /**
     * Rerank candidate chunks against the user question.
     *
     * Truncates candidates to candidateK before calling the cross-encoder,
     * then reorders by relevance score and keeps only finalK. Any failure
     * degrades gracefully to the original ordering.
     *
     * @param  string  $question  The search question. Example: "What is Q3 revenue?"
     * @param  array  $chunks  Candidate chunk objects with content property. Example: [["content" => "..."]]
     * @return array Reranked chunks capped at finalK, unchanged on failure. Example: [["content" => "..."]]
     */
    public function rerank(string $question, array $chunks): array
    {
        if (! $this->enabled || $chunks === []) {
            return $chunks;
        }

        $candidates = array_slice(array_values($chunks), 0, $this->candidateK);
        $contents = array_map(fn (object $c): string => (string) ($c->content ?? ''), $candidates);

        try {
            $response = Http::timeout($this->timeout)->post("{$this->baseUrl}/api/rerank", [
                'model' => $this->model,
                'query' => $question,
                'documents' => $contents,
            ]);

            if ($response->successful()) {
                $results = $response->json('results') ?? [];
                $scoreByIdx = [];
                foreach ($results as $r) {
                    $idx = (int) ($r['index'] ?? -1);
                    if ($idx >= 0 && $idx < count($candidates)) {
                        $scoreByIdx[$idx] = (float) ($r['relevance_score'] ?? 0.0);
                    }
                }

                // Re-score candidates using the cross-encoder relevance.
                foreach ($candidates as $i => $chunk) {
                    $chunk->similarity_score = $scoreByIdx[$i] ?? (float) $chunk->similarity_score;
                }

                usort($candidates, fn (object $a, object $b): int => (float) $b->similarity_score <=> (float) $a->similarity_score);

                return array_slice($candidates, 0, $this->finalK);
            }

            Log::channel(config('rag.logging.channel', 'rag'))->warning('Reranker returned non-200', [
                'status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::channel(config('rag.logging.channel', 'rag'))->warning('Reranker call failed, falling back to original ordering', [
                'error' => $e->getMessage(),
            ]);
        }

        return $chunks;
    }
}
