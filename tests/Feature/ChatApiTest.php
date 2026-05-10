<?php

declare(strict_types=1);

use Modules\ChatModule\Models\ChatSession;

test('test_chat_api_returns_error_for_empty_question', function (): void {
    $response = $this->postJson('/api/chat', ['question' => '']);

    $response->assertStatus(422);
});

test('test_chat_api_returns_session_list', function (): void {
    ChatSession::create([
        'title' => 'Test Session',
        'last_activity_at' => now(),
    ]);

    $response = $this->getJson('/api/chat/sessions');

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'success',
        'data',
    ]);
});

test('test_chat_api_shows_session', function (): void {
    $session = ChatSession::create([
        'title' => 'Test Session',
        'last_activity_at' => now(),
    ]);

    $response = $this->getJson("/api/chat/sessions/{$session->id}");

    $response->assertStatus(200);
    $response->assertJsonPath('success', true);
});

test('test_chat_api_deletes_session', function (): void {
    $session = ChatSession::create([
        'title' => 'Test Session',
        'last_activity_at' => now(),
    ]);

    $response = $this->deleteJson("/api/chat/sessions/{$session->id}");

    $response->assertStatus(200);
    $response->assertJsonPath('success', true);
});

test('test_chat_api_returns_404_for_nonexistent_session', function (): void {
    $response = $this->getJson('/api/chat/sessions/' . (string) \Illuminate\Support\Str::ulid());

    $response->assertStatus(404);
});
