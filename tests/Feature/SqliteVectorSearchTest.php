<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\DocumentModule\Models\Document;
use Modules\DocumentModule\Models\DocumentChunk;
use Modules\VectorStoreModule\Services\PgvectorDriver;

function seedSqliteChunk(Document $document, int $index, array $vector, PgvectorDriver $driver): string
{
    $chunk = DocumentChunk::create([
        'document_id' => $document->id,
        'content' => "Chunk {$index}",
        'chunk_index' => $index,
        'char_start' => 0,
        'char_end' => 7,
        'token_count' => 2,
        'metadata' => [],
    ]);

    $driver->upsert([$vector], [['model_name' => 'test-model']], [$chunk->id], "document_{$document->id}");

    return $chunk->id;
}

test('sqlite_search_scores_stored_embeddings_and_ranks_before_limiting', function (): void {
    $driver = new PgvectorDriver(DB::getFacadeRoot());
    $document = Document::create([
        'title' => 'Vector Doc',
        'original_filename' => 'v.txt',
        'file_path' => 'documents/v.txt',
        'file_hash' => hash('sha256', 'v'),
        'mime_type' => 'text/plain',
        'file_size' => 10,
        'status' => 'completed',
    ]);

    // Inserted worst-first so a SQL LIMIT before scoring would return the wrong rows.
    seedSqliteChunk($document, 0, [0.0, 1.0, 0.0], $driver);
    seedSqliteChunk($document, 1, [0.7, 0.7, 0.0], $driver);
    $best = seedSqliteChunk($document, 2, [1.0, 0.0, 0.0], $driver);

    $results = $driver->search([1.0, 0.0, 0.0], 1, ['similarity_threshold' => 0.5]);

    expect($results)->toHaveCount(1);
    expect($results[0]->chunk_id)->toBe($best);
    expect($results[0]->similarity_score)->toEqualWithDelta(1.0, 0.0001);
    expect(property_exists($results[0], 'embedding'))->toBeFalse();
});
