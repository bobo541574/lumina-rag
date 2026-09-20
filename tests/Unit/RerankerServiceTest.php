<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Modules\ChatModule\Services\RerankerService;

/**
 * Build sample chunk objects with a content and an initial similarity score.
 *
 * @param  array  $scores  Map of chunk content => initial similarity_score.
 * @return array List of chunk objects. Example: [["content" => "a", "similarity_score" => 0.9]]
 */
function makeRerankChunks(array $scores): array
{
    $chunks = [];
    foreach ($scores as $content => $score) {
        $chunks[] = (object) ['content' => $content, 'similarity_score' => $score];
    }

    return $chunks;
}

/**
 * A disabled reranker returns the chunks untouched.
 *
 * @return void
 */
test('disabled_reranker_returns_chunks_unchanged', function (): void {
    Http::fake();

    $chunks = makeRerankChunks(['alpha' => 0.9, 'beta' => 0.8]);
    $service = new RerankerService(enabled: false);

    expect($service->rerank('question', $chunks))->toBe($chunks);
    Http::assertNothingSent();
});

/**
 * An empty candidate list short-circuits without an HTTP call.
 *
 * @return void
 */
test('empty_chunks_short_circuit_without_http', function (): void {
    Http::fake();

    $service = new RerankerService(enabled: true);

    expect($service->rerank('question', []))->toBe([]);
    Http::assertNothingSent();
});

/**
 * A successful rerank reorders by relevance score and caps at finalK.
 *
 * The cross-encoder returns relevance scores that invert the original
 * ordering; the service must re-sort and keep the best finalK candidates.
 *
 * @return void
 */
test('successful_rerank_reorders_and_caps_at_final_k', function (): void {
    $chunks = makeRerankChunks(['A' => 0.9, 'B' => 0.8, 'C' => 0.7, 'D' => 0.6]);

    Http::fake([
        '*/api/rerank' => Http::response([
            'results' => [
                ['index' => 1, 'relevance_score' => 0.95],
                ['index' => 0, 'relevance_score' => 0.30],
            ],
        ]),
    ]);

    $service = new RerankerService(enabled: true, candidateK: 4, finalK: 2);
    $result = $service->rerank('question', $chunks);

    expect($result)->toHaveCount(2);
    expect((string) $result[0]->content)->toBe('B');
    expect((string) $result[1]->content)->toBe('C');
});

/**
 * Only candidateK chunks are sent to the cross-encoder.
 *
 * @return void
 */
test('only_candidate_k_candidates_are_sent', function (): void {
    $chunks = makeRerankChunks(['A' => 0.9, 'B' => 0.8, 'C' => 0.7, 'D' => 0.6]);
    $sentDocuments = [];

    Http::fake(function ($request) use (&$sentDocuments) {
        $sentDocuments = $request->data()['documents'];

        return Http::response(['results' => []]);
    });

    $service = new RerankerService(enabled: true, candidateK: 2, finalK: 1);
    $service->rerank('question', $chunks);

    expect($sentDocuments)->toBe(['A', 'B']);
});

/**
 * A non-200 response degrades gracefully to the original ordering.
 *
 * @return void
 */
test('non_200_response_falls_back_to_original_ordering', function (): void {
    $chunks = makeRerankChunks(['A' => 0.9, 'B' => 0.8]);

    Http::fake([
        '*/api/rerank' => Http::response([], 500),
    ]);

    $service = new RerankerService(enabled: true);
    $result = $service->rerank('question', $chunks);

    expect($result)->toBe($chunks);
});

/**
 * A thrown exception (e.g. connection refused) falls back to the original
 * ordering without surfacing the error to the caller.
 *
 * @return void
 */
test('exceptions_fall_back_to_original_ordering', function (): void {
    $chunks = makeRerankChunks(['A' => 0.9, 'B' => 0.8]);

    Http::fake(function (): never {
        throw new RuntimeException('connection refused');
    });

    $service = new RerankerService(enabled: true, baseUrl: 'http://localhost:1');
    $result = $service->rerank('question', $chunks);

    expect($result)->toBe($chunks);
});

/**
 * Candidate chunks with no cross-encoder score keep their initial score.
 *
 * Indices that are out of range or missing are skipped, and those candidates
 * retain their retrieval similarity_score rather than being zeroed.
 *
 * @return void
 */
test('missing_scores_keep_original_similarity_score', function (): void {
    $chunks = makeRerankChunks(['A' => 0.9, 'B' => 0.8, 'C' => 0.7]);

    Http::fake([
        '*/api/rerank' => Http::response([
            'results' => [['index' => 99, 'relevance_score' => 0.99]],
        ]),
    ]);

    $service = new RerankerService(enabled: true, candidateK: 3, finalK: 3);
    $result = $service->rerank('question', $chunks);

    // No candidate had a matching score, so ordering stays by initial score.
    expect((string) $result[0]->content)->toBe('A');
    expect((float) $result[0]->similarity_score)->toBe(0.9);
});
