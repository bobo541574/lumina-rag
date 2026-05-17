<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\DocumentModule\Contracts\TextChunkingServiceInterface;
use Modules\DocumentModule\Models\Document;
use Modules\DocumentModule\Models\DocumentChunk;

function makeDocument(array $overrides = []): Document
{
    $defaults = [
        'title' => 'Test Report',
        'original_filename' => 'test.txt',
        'file_path' => 'documents/test.txt',
        'file_hash' => hash('sha256', (string) microtime(true)),
        'mime_type' => 'text/plain',
        'file_size' => 100,
        'status' => 'pending',
    ];

    return Document::create(array_merge($defaults, $overrides));
}

test('document_chunk_metadata_contains_document_fields', function (): void {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
        'api_token' => 'meta-test-token',
    ]);

    $document = makeDocument([
        'user_id' => $user->id,
        'title' => 'Q1 Report',
        'project' => 'Orion',
        'report_date' => '2026-05-01',
    ]);

    $chunker = app(TextChunkingServiceInterface::class);
    $text = "# Introduction\n\nThis is the intro section.\n\n# Details\n\nDetailed content with enough text to span multiple chunks.\n\n# Conclusion\n\nFinal remarks and summary.";
    $rawChunks = $chunker->chunk($text, 1000, 200);

    $now = now();
    $records = [];
    $ids = [];

    foreach ($rawChunks as $i => $chunkData) {
        $id = (string) Str::ulid();
        $ids[] = $id;

        $chunkMeta = [
            'user_id' => $document->user_id,
            'user_name' => $user->name,
            'project' => $document->project,
            'report_date' => '2026-05-01',
            'document_title' => $document->title,
            'chunk_index' => $i,
            'section' => $chunkData['section'] ?? null,
            'page_number' => $chunkData['page_number'] ?? null,
        ];
        $chunkMeta = array_filter($chunkMeta, fn ($v): bool => $v !== null);

        $records[] = [
            'id' => $id,
            'document_id' => $document->id,
            'content' => $chunkData['content'],
            'chunk_index' => $i,
            'char_start' => $chunkData['char_start'],
            'char_end' => $chunkData['char_end'],
            'token_count' => (int) ceil(mb_strlen($chunkData['content']) / 4),
            'page_number' => $chunkData['page_number'] ?? null,
            'metadata' => json_encode($chunkMeta),
        ];
    }

    DB::table('document_chunks')->insert($records);

    $savedChunks = DocumentChunk::where('document_id', $document->id)
        ->orderBy('chunk_index')
        ->get();

    expect($savedChunks)->not->toBeEmpty();

    foreach ($savedChunks as $chunk) {
        $meta = $chunk->metadata;
        expect($meta)->toHaveKey('user_id', $document->user_id);
        expect($meta)->toHaveKey('user_name', 'John Doe');
        expect($meta)->toHaveKey('project', 'Orion');
        expect($meta)->toHaveKey('report_date', '2026-05-01');
        expect($meta)->toHaveKey('document_title', 'Q1 Report');
        expect($meta)->toHaveKey('chunk_index');
        expect($meta['chunk_index'])->toBeInt();
    }
});

test('document_chunk_metadata_captures_section_heading', function (): void {
    $user = User::create([
        'name' => 'Jane',
        'email' => 'jane@example.com',
        'password' => Hash::make('password'),
        'api_token' => 'meta-section-token',
    ]);

    $document = makeDocument([
        'user_id' => $user->id,
        'title' => 'Sectioned Report',
        'project' => 'Alpha',
    ]);

    $chunker = app(TextChunkingServiceInterface::class);

    // Use small chunk size to force splits across sections
    $rawChunks = $chunker->chunk(
        "# Executive Summary\n\nQuick overview.\n\n# Financial Analysis\n\nDetailed financial breakdown.\n\n# Appendix\n\nSupporting data.",
        50,
        10,
    );

    $chunksWithSections = array_filter($rawChunks, fn ($c) => isset($c['section']) && $c['section'] !== null);

    expect($chunksWithSections)->not->toBeEmpty();

    $sectionNames = array_unique(array_map(fn ($c) => $c['section'], $chunksWithSections));
    expect($sectionNames)->toContain('Executive Summary');
    expect($sectionNames)->toContain('Financial Analysis');
    expect($sectionNames)->toContain('Appendix');
});

test('document_chunk_metadata_accepts_empty_array', function (): void {
    $user = User::create([
        'name' => 'Empty Meta Test',
        'email' => 'empty-meta@example.com',
        'password' => Hash::make('password'),
        'api_token' => 'meta-empty-token',
    ]);

    $document = makeDocument([
        'user_id' => $user->id,
        'title' => 'Empty Meta Test',
    ]);

    $chunk = DocumentChunk::create([
        'document_id' => $document->id,
        'content' => 'Test content',
        'chunk_index' => 0,
        'char_start' => 0,
        'char_end' => 12,
        'token_count' => 3,
        'metadata' => [],
    ]);

    expect($chunk->metadata)->toBe([]);
});
