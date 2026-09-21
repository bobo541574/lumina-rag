<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

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
