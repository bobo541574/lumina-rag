<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\ChatModule\Models\ChatSession;

/**
 * Create an authenticated chat-test user
 *
 * Creates a user with a known email and generates a unique API token
 * for use in chat API test requests.
 *
 * @return array{user: User, headers: array<string, string>} The created user and authorization headers
 *                                                           Example: ['user' => User{...}, 'headers' => ['Authorization' => 'Bearer <token>']]
 */
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

/**
 * Chat API returns validation error for empty question
 *
 * Posts an empty question string to the chat endpoint and verifies
 * that the API responds with a 422 Unprocessable Entity status.
 *
 * @return void
 */
test('test_chat_api_returns_error_for_empty_question', function (): void {
    $auth = createAuthenticatedUser();

    $response = $this->withHeaders($auth['headers'])
        ->postJson('/api/chat', ['question' => '']);

    $response->assertStatus(422);
});

/**
 * Chat API returns list of sessions
 *
 * Creates a chat session for the authenticated user, then fetches
 * the session list and asserts a 200 response with the expected JSON envelope.
 *
 * @return void
 */
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

/**
 * Chat API shows a single session
 *
 * Creates a session then fetches it by ID, verifying a 200 response
 * with success flag set to true.
 *
 * @return void
 */
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

/**
 * Chat API deletes a session
 *
 * Creates a session then deletes it via DELETE endpoint, verifying
 * a 200 response with success flag.
 *
 * @return void
 */
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

/**
 * Chat API returns 404 for a session that does not exist
 *
 * Generates a random ULID that does not correspond to any session
 * and verifies the API returns a 404 Not Found status.
 *
 * @return void
 */
test('test_chat_api_returns_404_for_nonexistent_session', function (): void {
    $auth = createAuthenticatedUser();

    $response = $this->withHeaders($auth['headers'])
        ->getJson('/api/chat/sessions/'.(string) Str::ulid());

    $response->assertStatus(404);
});

/**
 * Chat API rejects messages in an expired session
 *
 * Creates a session with last_activity_at set to 2 days ago,
 * then attempts to post a question. Asserts a 422 response with
 * an expiration error message.
 *
 * @return void
 */
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

/**
 * Chat cleanup artisan command removes stale sessions
 *
 * Creates a session with last_activity_at 31 days ago, runs the
 * chat:cleanup command, then asserts the stale session is gone.
 *
 * @return void
 */
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
