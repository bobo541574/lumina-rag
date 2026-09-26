<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\DocumentModule\Enums\DocumentStatus;
use Modules\DocumentModule\Models\Document;
use Modules\DocumentModule\Models\DocumentChunk;
use Modules\DocumentModule\Services\ProcessDocumentService;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;

function processFixture(): Document
{
    Storage::fake();
    Storage::put('documents/report.txt', str_repeat('Orion revenue grew in the third quarter. ', 80));

    $user = User::create([
        'name' => 'Aung Zeya',
        'email' => 'aung@example.com',
        'password' => Hash::make('password123'),
    ]);

    return Document::create([
        'user_id' => $user->id,
        'title' => 'Q3 Report',
        'project' => 'Orion',
        'report_date' => '2026-05-01',
        'original_filename' => 'report.txt',
        'file_path' => 'documents/report.txt',
        'file_hash' => hash('sha256', 'report'),
        'mime_type' => 'text/plain',
        'file_size' => 100,
        'status' => 'pending',
    ]);
}

test('process_writes_chunks_with_shared_metadata_and_one_vector_each', function (): void {
    $document = processFixture();

    $embedder = mock(EmbeddingServiceInterface::class);
    $embedder->shouldReceive('embedBatch')->andReturnUsing(fn (array $texts): array => array_map(fn () => [0.1, 0.2, 0.3], $texts));
    app()->instance(EmbeddingServiceInterface::class, $embedder);

    app(ProcessDocumentService::class)->process($document->id);

    $document->refresh();
    $chunks = DocumentChunk::where('document_id', $document->id)->orderBy('chunk_index')->get();

    expect($document->status)->toBe(DocumentStatus::Completed);
    expect($document->chunks_count)->toBe($chunks->count())->toBeGreaterThan(1);
    expect($chunks[0]->content)->toStartWith("Report by: Aung Zeya\nProject: Orion\nDate: 2026-05-01\n\n");
    expect($chunks[0]->metadata)->toMatchArray([
        'user_name' => 'Aung Zeya',
        'project' => 'Orion',
        'report_date' => '2026-05-01',
        'document_title' => 'Q3 Report',
        'chunk_index' => 0,
    ]);
    expect(DB::table('vector_embeddings')->whereIn('chunk_id', $chunks->pluck('id'))->count())->toBe($chunks->count());
});

test('process_keeps_document_processing_when_the_queue_will_retry', function (): void {
    $document = processFixture();

    $embedder = mock(EmbeddingServiceInterface::class);
    $embedder->shouldReceive('embedBatch')->andThrow(new RuntimeException('provider down'));
    app()->instance(EmbeddingServiceInterface::class, $embedder);

    expect(fn () => app(ProcessDocumentService::class)->process($document->id, finalAttempt: false))
        ->toThrow(RuntimeException::class);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Processing);
    expect($document->error_message)->toBe('provider down');
});

test('process_marks_failed_on_the_final_attempt', function (): void {
    $document = processFixture();

    $embedder = mock(EmbeddingServiceInterface::class);
    $embedder->shouldReceive('embedBatch')->andThrow(new RuntimeException('provider down'));
    app()->instance(EmbeddingServiceInterface::class, $embedder);

    expect(fn () => app(ProcessDocumentService::class)->process($document->id))->toThrow(RuntimeException::class);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Failed);
    expect(DocumentChunk::where('document_id', $document->id)->count())->toBe(0);
});

test('process_skips_documents_deleted_after_the_job_was_queued', function (): void {
    app(ProcessDocumentService::class)->process('01M310AMVGQ5XSE7JSFZQ2DNXF');

    expect(DocumentChunk::count())->toBe(0);
});
