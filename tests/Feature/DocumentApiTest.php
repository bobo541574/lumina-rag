<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Modules\DocumentModule\Models\Document;

test('test_document_upload_rejects_invalid_file_type', function (): void {
    $file = UploadedFile::fake()->create('test.exe', 100);

    $response = $this->postJson('/api/documents', [
        'file' => $file,
    ]);

    $response->assertStatus(422);
});

test('test_document_upload_rejects_oversized_file', function (): void {
    $file = UploadedFile::fake()->create('test.pdf', 51201);

    $response = $this->postJson('/api/documents', [
        'file' => $file,
    ]);

    $response->assertStatus(422);
});

test('test_document_list_returns_paginated_results', function (): void {
    Document::create([
        'title' => 'Test Document',
        'original_filename' => 'test.pdf',
        'file_path' => 'documents/test.pdf',
        'file_size' => 1000,
        'mime_type' => 'application/pdf',
        'file_hash' => md5('test'),
        'status' => 'completed',
    ]);

    $response = $this->getJson('/api/documents');

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'success',
        'data',
    ]);
});

test('test_document_show_returns_document', function (): void {
    $doc = Document::create([
        'title' => 'Test Document',
        'original_filename' => 'test.pdf',
        'file_path' => 'documents/test.pdf',
        'file_size' => 1000,
        'mime_type' => 'application/pdf',
        'file_hash' => md5('test-show'),
        'status' => 'completed',
    ]);

    $response = $this->getJson("/api/documents/{$doc->id}");

    $response->assertStatus(200);
    $response->assertJsonPath('success', true);
});

test('test_document_delete_removes_document', function (): void {
    $doc = Document::create([
        'title' => 'Test Document',
        'original_filename' => 'test.pdf',
        'file_path' => 'documents/test.pdf',
        'file_size' => 1000,
        'mime_type' => 'application/pdf',
        'file_hash' => md5('test-delete'),
        'status' => 'completed',
    ]);

    $response = $this->deleteJson("/api/documents/{$doc->id}");

    $response->assertStatus(200);
    expect(Document::find($doc->id))->toBeNull();
});
