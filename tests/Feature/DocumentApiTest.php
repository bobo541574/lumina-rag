<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Modules\DocumentModule\Models\Document;

/**
 * Create an authenticated document-test user
 *
 * Creates a user with a known email and generates a unique API token
 * for use in document API test requests.
 *
 * @return array{user: User, headers: array<string, string>} The created user and authorization headers
 *                                                           Example: ['user' => User{...}, 'headers' => ['Authorization' => 'Bearer <token>']]
 */
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

/**
 * Document upload rejects files with invalid MIME types
 *
 * Attempts to upload a .exe file (not in the allowed types list)
 * and verifies a 422 validation error is returned.
 *
 * @return void
 */
test('test_document_upload_rejects_invalid_file_type', function (): void {
    $auth = createDocumentTestUser();
    $file = UploadedFile::fake()->create('test.exe', 100);

    $response = $this->withHeaders($auth['headers'])
        ->postJson('/api/documents', [
            'file' => $file,
        ]);

    $response->assertStatus(422);
});

/**
 * Document upload rejects files exceeding the maximum size
 *
 * Creates a fake PDF that exceeds the configured upload size limit
 * and verifies a 422 validation error.
 *
 * @return void
 */
test('test_document_upload_rejects_oversized_file', function (): void {
    $auth = createDocumentTestUser();
    $file = UploadedFile::fake()->create('test.pdf', 51201);

    $response = $this->withHeaders($auth['headers'])
        ->postJson('/api/documents', [
            'file' => $file,
        ]);

    $response->assertStatus(422);
});

/**
 * Document list returns paginated results
 *
 * Creates a completed document, then fetches the document list
 * and verifies a 200 response with the expected JSON envelope.
 *
 * @return void
 */
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

/**
 * Document show returns a single document
 *
 * Creates a document then fetches it by ID, verifying a 200 response
 * with the success flag set to true.
 *
 * @return void
 */
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

/**
 * Document delete removes the document from the database
 *
 * Creates a document, deletes it via the DELETE endpoint, then
 * asserts the document can no longer be found in the database.
 *
 * @return void
 */
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
