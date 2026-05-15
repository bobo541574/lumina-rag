<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Modules\DocumentModule\Models\Document;

function createDocumentTestUser(): array
{
    $user = User::create([
        'name' => 'Doc Test User',
        'email' => 'doc-test@example.com',
        'password' => Hash::make('password123'),
        'api_token' => 'doc-test-token-'.bin2hex(random_bytes(8)),
    ]);

    return [
        'user' => $user,
        'headers' => ['Authorization' => 'Bearer '.$user->api_token],
    ];
}

test('test_document_upload_rejects_invalid_file_type', function (): void {
    $auth = createDocumentTestUser();
    $file = UploadedFile::fake()->create('test.exe', 100);

    $response = $this->withHeaders($auth['headers'])
        ->postJson('/api/documents', [
            'file' => $file,
        ]);

    $response->assertStatus(422);
});

test('test_document_upload_rejects_oversized_file', function (): void {
    $auth = createDocumentTestUser();
    $file = UploadedFile::fake()->create('test.pdf', 51201);

    $response = $this->withHeaders($auth['headers'])
        ->postJson('/api/documents', [
            'file' => $file,
        ]);

    $response->assertStatus(422);
});

test('test_document_list_returns_paginated_results', function (): void {
    $auth = createDocumentTestUser();

    Document::create([
        'title' => 'Test Document',
        'original_filename' => 'test.pdf',
        'file_path' => 'documents/test.pdf',
        'file_size' => 1000,
        'mime_type' => 'application/pdf',
        'file_hash' => md5('test'),
        'status' => 'completed',
        'user_id' => $auth['user']->id,
    ]);

    $response = $this->withHeaders($auth['headers'])
        ->getJson('/api/documents');

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'success',
        'data',
    ]);
});

test('test_document_show_returns_document', function (): void {
    $auth = createDocumentTestUser();

    $doc = Document::create([
        'title' => 'Test Document',
        'original_filename' => 'test.pdf',
        'file_path' => 'documents/test.pdf',
        'file_size' => 1000,
        'mime_type' => 'application/pdf',
        'file_hash' => md5('test-show'),
        'status' => 'completed',
        'user_id' => $auth['user']->id,
    ]);

    $response = $this->withHeaders($auth['headers'])
        ->getJson("/api/documents/{$doc->id}");

    $response->assertStatus(200);
    $response->assertJsonPath('success', true);
});

test('test_document_delete_removes_document', function (): void {
    $auth = createDocumentTestUser();

    $doc = Document::create([
        'title' => 'Test Document',
        'original_filename' => 'test.pdf',
        'file_path' => 'documents/test.pdf',
        'file_size' => 1000,
        'mime_type' => 'application/pdf',
        'file_hash' => md5('test-delete'),
        'status' => 'completed',
        'user_id' => $auth['user']->id,
    ]);

    $response = $this->withHeaders($auth['headers'])
        ->deleteJson("/api/documents/{$doc->id}");

    $response->assertStatus(200);
    expect(Document::find($doc->id))->toBeNull();
});
