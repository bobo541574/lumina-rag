<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\ChatModule\Events\KnowledgeChanged;
use Modules\DocumentModule\Models\Document;
use Modules\DocumentModule\Models\DocumentChunk;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

test('test_auth_register_creates_user_and_returns_token', function (): void {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure([
        'success',
        'message',
        'data' => [
            'user' => ['id', 'name', 'email'],
            'token',
        ],
    ]);
    $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
});

test('test_auth_register_rejects_duplicate_email', function (): void {
    User::create([
        'name' => 'Existing',
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ]);

    $response = $this->postJson('/api/auth/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(422);
});

test('test_auth_login_returns_token_for_valid_credentials', function (): void {
    User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => Hash::make('password123'),
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'test@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'success',
        'data' => [
            'user' => ['id', 'name', 'email'],
            'token',
        ],
    ]);
});

test('test_auth_login_rejects_invalid_credentials', function (): void {
    $response = $this->postJson('/api/auth/login', [
        'email' => 'wrong@example.com',
        'password' => 'wrongpassword',
    ]);

    $response->assertStatus(401);
});

test('test_auth_logout_invalidates_token', function (): void {
    // The DB stores only the SHA-256 digest; the raw token is used in headers.
    $rawToken = 'test-token-123';

    User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => Hash::make('password123'),
        'api_token' => hash('sha256', $rawToken),
    ]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawToken])
        ->postJson('/api/auth/logout');

    $response->assertStatus(200);
    $this->assertDatabaseMissing('users', ['api_token' => hash('sha256', $rawToken)]);
});

test('test_auth_me_returns_authenticated_user', function (): void {
    $rawToken = 'test-token-123';

    User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => Hash::make('password123'),
        'api_token' => hash('sha256', $rawToken),
    ]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawToken])
        ->getJson('/api/auth/me');

    $response->assertStatus(200);
    $response->assertJsonPath('data.name', 'Test User');
    $response->assertJsonPath('data.email', 'test@example.com');
});

test('test_auth_me_returns_401_without_token', function (): void {
    $response = $this->getJson('/api/auth/me');
    $response->assertStatus(401);
});

test('test_auth_delete_account_erases_documents_vectors_and_notifies_cache', function (): void {
    Event::fake([KnowledgeChanged::class]);
    Storage::fake();

    $rawToken = 'delete-me-token';
    $user = User::create([
        'name' => 'Leaving User',
        'email' => 'leaving@example.com',
        'password' => Hash::make('password123'),
        'api_token' => hash('sha256', $rawToken),
    ]);

    $makeDoc = fn (string $title) => Document::create([
        'user_id' => $user->id,
        'title' => $title,
        'original_filename' => "{$title}.txt",
        'file_path' => "documents/{$title}.txt",
        'file_hash' => hash('sha256', $title),
        'mime_type' => 'text/plain',
        'file_size' => 10,
        'status' => 'completed',
    ]);

    $live = $makeDoc('live');
    $trashed = $makeDoc('trashed');
    $trashed->delete();

    $chunk = DocumentChunk::create([
        'document_id' => $live->id,
        'content' => 'Private content',
        'chunk_index' => 0,
        'char_start' => 0,
        'char_end' => 15,
        'token_count' => 3,
        'metadata' => [],
    ]);
    app(VectorStoreInterface::class)
        ->upsert([[0.1, 0.2, 0.3]], [['model_name' => 'm']], [$chunk->id], "document_{$live->id}");

    $this->withHeaders(['Authorization' => 'Bearer '.$rawToken])
        ->deleteJson('/api/auth/me')
        ->assertStatus(200);

    expect(Document::withTrashed()->where('user_id', $user->id)->count())->toBe(0);
    $this->assertDatabaseMissing('document_chunks', ['id' => $chunk->id]);
    $this->assertDatabaseMissing('vector_embeddings', ['chunk_id' => $chunk->id]);
    Event::assertDispatched(
        KnowledgeChanged::class,
        fn ($event): bool => count($event->documentIds) === 2,
    );
});
