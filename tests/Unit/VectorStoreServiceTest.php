<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\VectorStoreModule\Services\PgvectorDriver;

function makeDriver(): PgvectorDriver
{
    return new PgvectorDriver(DB::getFacadeRoot());
}

function callFuseResults(array $vectorResults, array $ftsResults, int $topK = 70): array
{
    $reflection = new ReflectionMethod(PgvectorDriver::class, 'fuseResults');
    $reflection->setAccessible(true);

    return $reflection->invoke(makeDriver(), $vectorResults, $ftsResults, $topK);
}

test('fuse_results_returns_empty_when_no_input', function (): void {
    $this->assertSame([], callFuseResults([], []));
});

test('fuse_results_handles_vector_only', function (): void {
    $vectorResults = [
        (object) ['chunk_id' => 'a', 'similarity_score' => 0.9, 'content' => 'chunk a'],
        (object) ['chunk_id' => 'b', 'similarity_score' => 0.8, 'content' => 'chunk b'],
    ];
    $result = callFuseResults($vectorResults, []);

    $this->assertCount(2, $result);
    $this->assertSame('a', $result[0]->chunk_id);
    $this->assertSame(1.0, $result[0]->similarity_score);
    $this->assertSame('b', $result[1]->chunk_id);
});

test('fuse_results_handles_fts_only', function (): void {
    $ftsResults = [
        (object) ['chunk_id' => 'x', 'similarity_score' => 0.9, 'content' => 'chunk x'],
    ];
    $result = callFuseResults([], $ftsResults);

    $this->assertCount(1, $result);
    $this->assertSame('x', $result[0]->chunk_id);
    $this->assertSame(1.0, $result[0]->similarity_score);
});

test('fuse_results_combines_vector_and_fts', function (): void {
    $vectorResults = [
        (object) ['chunk_id' => 'a', 'similarity_score' => 0.9, 'content' => 'chunk a'],
        (object) ['chunk_id' => 'b', 'similarity_score' => 0.8, 'content' => 'chunk b'],
    ];
    $ftsResults = [
        (object) ['chunk_id' => 'b', 'similarity_score' => 0.7, 'content' => 'chunk b'],
        (object) ['chunk_id' => 'c', 'similarity_score' => 0.6, 'content' => 'chunk c'],
    ];
    $result = callFuseResults($vectorResults, $ftsResults);

    $this->assertCount(3, $result);
    $this->assertSame('b', $result[0]->chunk_id);
    $this->assertSame('a', $result[1]->chunk_id);
    $this->assertSame('c', $result[2]->chunk_id);
});

test('fuse_results_respects_top_k', function (): void {
    $vectorResults = [
        (object) ['chunk_id' => 'a', 'similarity_score' => 0.9, 'content' => 'chunk a'],
        (object) ['chunk_id' => 'b', 'similarity_score' => 0.8, 'content' => 'chunk b'],
        (object) ['chunk_id' => 'c', 'similarity_score' => 0.7, 'content' => 'chunk c'],
    ];
    $result = callFuseResults($vectorResults, [], 2);

    $this->assertCount(2, $result);
    $this->assertSame('a', $result[0]->chunk_id);
    $this->assertSame('b', $result[1]->chunk_id);
});

test('fuse_results_interleaves_results_by_score', function (): void {
    $vectorResults = [
        (object) ['chunk_id' => 'v1', 'similarity_score' => 0.9, 'content' => 'v1'],
        (object) ['chunk_id' => 'v2', 'similarity_score' => 0.5, 'content' => 'v2'],
    ];
    $ftsResults = [
        (object) ['chunk_id' => 'f1', 'similarity_score' => 0.8, 'content' => 'f1'],
        (object) ['chunk_id' => 'f2', 'similarity_score' => 0.4, 'content' => 'f2'],
    ];
    $result = callFuseResults($vectorResults, $ftsResults, 4);

    $this->assertCount(4, $result);
    $this->assertSame('v1', $result[0]->chunk_id);
    $this->assertSame('f1', $result[1]->chunk_id);
    $this->assertSame('v2', $result[2]->chunk_id);
    $this->assertSame('f2', $result[3]->chunk_id);
});
