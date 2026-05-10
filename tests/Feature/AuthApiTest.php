<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

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
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => Hash::make('password123'),
        'api_token' => 'test-token-123',
    ]);

    $response = $this->withHeaders(['Authorization' => 'Bearer test-token-123'])
        ->postJson('/api/auth/logout');

    $response->assertStatus(200);
    $user->refresh();
    expect($user->api_token)->toBeNull();
});

test('test_auth_me_returns_authenticated_user', function (): void {
    User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => Hash::make('password123'),
        'api_token' => 'test-token-123',
    ]);

    $response = $this->withHeaders(['Authorization' => 'Bearer test-token-123'])
        ->getJson('/api/auth/me');

    $response->assertStatus(200);
    $response->assertJsonPath('data.name', 'Test User');
    $response->assertJsonPath('data.email', 'test@example.com');
});

test('test_auth_me_returns_401_without_token', function (): void {
    $response = $this->getJson('/api/auth/me');
    $response->assertStatus(401);
});
