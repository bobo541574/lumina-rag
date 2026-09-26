<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\ApiToken;
use Illuminate\Support\Facades\Hash;

function logViewerAdminUser(): User
{
    return User::create([
        'name' => 'Admin',
        'email' => 'log-admin@test.example',
        'password' => Hash::make('password123'),
        'is_admin' => true,
        'api_token' => ApiToken::hash('log-viewer-admin-token'),
    ]);
}

function logViewerMemberUser(): User
{
    return User::create([
        'name' => 'Member',
        'email' => 'log-member@test.example',
        'password' => Hash::make('password123'),
        'is_admin' => false,
        'api_token' => ApiToken::hash('log-viewer-member-token'),
    ]);
}

test('log viewer page rejects unauthenticated requests', function (): void {
    $this->get('/log-viewer')
        ->assertStatus(401);
});

test('log viewer api rejects unauthenticated requests', function (): void {
    $this->get('/log-viewer/api/files')
        ->assertStatus(401);
});

test('log viewer page allows admin with valid bearer token', function (): void {
    logViewerAdminUser();

    $this->withToken('log-viewer-admin-token')
        ->get('/log-viewer')
        ->assertOk();
});

test('log viewer api allows admin with valid bearer token', function (): void {
    logViewerAdminUser();

    $this->withToken('log-viewer-admin-token')
        ->get('/log-viewer/api/files')
        ->assertOk();
});

test('log viewer blocks non-admin users', function (): void {
    logViewerMemberUser();

    $this->withToken('log-viewer-member-token')
        ->get('/log-viewer')
        ->assertStatus(403);
});

test('log viewer api blocks non-admin users', function (): void {
    logViewerMemberUser();

    $this->withToken('log-viewer-member-token')
        ->get('/log-viewer/api/files')
        ->assertStatus(403);
});
