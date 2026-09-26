<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\DocumentModule\Jobs\ReEmbedDocumentJob;
use Modules\DocumentModule\Models\Document;
use Modules\DocumentModule\Models\DocumentChunk;

function reEmbedCommandDoc(string $title, ?string $vectorModel, ?string $documentModel = null, string $status = 'completed'): Document
{
    $document = Document::create([
        'title' => $title,
        'original_filename' => "{$title}.txt",
        'file_path' => "documents/{$title}.txt",
        'file_hash' => hash('sha256', $title),
        'mime_type' => 'text/plain',
        'file_size' => 10,
        'status' => $status,
        'embedding_model' => $documentModel,
    ]);
    $chunk = DocumentChunk::create([
        'document_id' => $document->id,
        'content' => 'content',
        'chunk_index' => 0,
        'char_start' => 0,
        'char_end' => 7,
        'token_count' => 2,
        'metadata' => [],
    ]);

    if ($vectorModel !== null) {
        DB::table('vector_embeddings')->insert([
            'id' => (string) Str::ulid(),
            'chunk_id' => $chunk->id,
            'embedding' => '[0.1]',
            'dimensions' => 1,
            'model_name' => $vectorModel,
            'content_hash' => 'h',
            'created_at' => now(),
        ]);
    }

    return $document;
}

beforeEach(function (): void {
    Queue::fake();
    config(['rag.embedding.model' => 'default-model']);
});

test('reembed_missing_queues_only_documents_with_unembedded_chunks', function (): void {
    $missing = reEmbedCommandDoc('missing', null);
    reEmbedCommandDoc('embedded', 'default-model');

    $this->artisan('rag:reembed', ['--missing' => true])->assertSuccessful();

    Queue::assertPushed(ReEmbedDocumentJob::class, 1);
    Queue::assertPushed(ReEmbedDocumentJob::class, fn ($job): bool => $job->documentId === $missing->id);
});

test('reembed_only_old_model_compares_with_each_documents_own_model', function (): void {
    reEmbedCommandDoc('current-default', 'default-model');
    reEmbedCommandDoc('current-custom', 'custom-model', 'custom-model');
    $stale = reEmbedCommandDoc('stale', 'old-model');

    $this->artisan('rag:reembed', ['--only-old-model' => true])->assertSuccessful();

    Queue::assertPushed(ReEmbedDocumentJob::class, 1);
    Queue::assertPushed(ReEmbedDocumentJob::class, fn ($job): bool => $job->documentId === $stale->id);
});

test('reembed_rejects_unknown_status', function (): void {
    $this->artisan('rag:reembed', ['--status' => 'done'])->assertFailed();

    Queue::assertNothingPushed();
});

test('reembed_dry_run_queues_nothing', function (): void {
    reEmbedCommandDoc('missing', null);

    $this->artisan('rag:reembed', ['--missing' => true, '--dry-run' => true])->assertSuccessful();

    Queue::assertNothingPushed();
});
