<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\ChatModule\Models\ChatSession;

function createAuthenticatedUser(): array
{
    $user = User::create([
        'name' => 'Chat Test User',
        'email' => 'chat-test@example.com',
        'password' => Hash::make('password123'),
        'api_token' => 'chat-test-token-'.bin2hex(random_bytes(8)),
    ]);

    return [
        'user' => $user,
        'headers' => ['Authorization' => 'Bearer '.$user->api_token],
    ];
}

test('test_chat_api_returns_error_for_empty_question', function (): void {
    $auth = createAuthenticatedUser();

    $response = $this->withHeaders($auth['headers'])
        ->postJson('/api/chat', ['question' => '']);

    $response->assertStatus(422);
});

test('test_chat_api_returns_session_list', function (): void {
    $auth = createAuthenticatedUser();

    ChatSession::create([
        'title' => 'Test Session',
        'last_activity_at' => now(),
        'user_id' => $auth['user']->id,
    ]);

    $response = $this->withHeaders($auth['headers'])
        ->getJson('/api/chat/sessions');

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'success',
        'data',
    ]);
});

test('test_chat_api_shows_session', function (): void {
    $auth = createAuthenticatedUser();

    $session = ChatSession::create([
        'title' => 'Test Session',
        'last_activity_at' => now(),
        'user_id' => $auth['user']->id,
    ]);

    $response = $this->withHeaders($auth['headers'])
        ->getJson("/api/chat/sessions/{$session->id}");

    $response->assertStatus(200);
    $response->assertJsonPath('success', true);
});

test('test_chat_api_deletes_session', function (): void {
    $auth = createAuthenticatedUser();

    $session = ChatSession::create([
        'title' => 'Test Session',
        'last_activity_at' => now(),
        'user_id' => $auth['user']->id,
    ]);

    $response = $this->withHeaders($auth['headers'])
        ->deleteJson("/api/chat/sessions/{$session->id}");

    $response->assertStatus(200);
    $response->assertJsonPath('success', true);
});

test('test_chat_api_returns_404_for_nonexistent_session', function (): void {
    $auth = createAuthenticatedUser();

    $response = $this->withHeaders($auth['headers'])
        ->getJson('/api/chat/sessions/'.(string) Str::ulid());

    $response->assertStatus(404);
});

test('test_chat_api_rejects_expired_session', function (): void {
    $auth = createAuthenticatedUser();

    $session = ChatSession::create([
        'title' => 'Expired Session',
        'last_activity_at' => now()->subDays(2),
        'user_id' => $auth['user']->id,
    ]);

    $response = $this->withHeaders($auth['headers'])
        ->postJson('/api/chat', [
            'question' => 'test question',
            'session_id' => $session->id,
            'stream' => false,
        ]);

    $response->assertStatus(422);
    $response->assertJsonPath('message', 'Chat session has expired. Please start a new chat.');
});

test('test_chat_cleanup_removes_stale_sessions', function (): void {
    $auth = createAuthenticatedUser();

    ChatSession::create([
        'title' => 'Stale Session',
        'last_activity_at' => now()->subDays(31),
        'user_id' => $auth['user']->id,
    ]);

    $this->artisan('chat:cleanup')
        ->assertSuccessful();

    expect(ChatSession::where('title', 'Stale Session')->count())->toBe(0);
});
