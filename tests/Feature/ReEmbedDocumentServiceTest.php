<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\DocumentModule\Models\Document;
use Modules\DocumentModule\Models\DocumentChunk;
use Modules\DocumentModule\Services\ReEmbedDocumentService;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

function reEmbedFixture(): array
{
    $document = Document::create([
        'title' => 'Re-embed Doc',
        'original_filename' => 'r.txt',
        'file_path' => 'documents/r.txt',
        'file_hash' => hash('sha256', 'r'),
        'mime_type' => 'text/plain',
        'file_size' => 10,
        'status' => 'completed',
    ]);
    $chunk = DocumentChunk::create([
        'document_id' => $document->id,
        'content' => 'Some content',
        'chunk_index' => 0,
        'char_start' => 0,
        'char_end' => 12,
        'token_count' => 3,
        'metadata' => [],
    ]);
    app(VectorStoreInterface::class)->upsert([[0.1, 0.2, 0.3]], [['model_name' => 'old']], [$chunk->id], "document_{$document->id}");

    return [$document, $chunk];
}

test('reembed_keeps_old_vectors_when_the_provider_fails', function (): void {
    [$document, $chunk] = reEmbedFixture();

    $embedder = mock(EmbeddingServiceInterface::class);
    $embedder->shouldReceive('embedBatch')->andThrow(new RuntimeException('provider down'));
    app()->instance(EmbeddingServiceInterface::class, $embedder);

    expect(fn () => app(ReEmbedDocumentService::class)->reEmbed($document->id))
        ->toThrow(RuntimeException::class, 'provider down');

    expect(DB::table('vector_embeddings')->where('chunk_id', $chunk->id)->value('model_name'))->toBe('old');
});

test('reembed_replaces_vectors_on_success', function (): void {
    [$document, $chunk] = reEmbedFixture();

    $embedder = mock(EmbeddingServiceInterface::class);
    $embedder->shouldReceive('embedBatch')->andReturn([[0.4, 0.5, 0.6]]);
    app()->instance(EmbeddingServiceInterface::class, $embedder);

    app(ReEmbedDocumentService::class)->reEmbed($document->id);

    $rows = DB::table('vector_embeddings')->where('chunk_id', $chunk->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->model_name)->not->toBe('old');
});
