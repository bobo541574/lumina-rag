<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\SettingsModule\Models\AiModel;

function createAiModelTestUser(): array
{
    // The DB stores only the SHA-256 digest; the raw token is used in headers.
    // Settings endpoints are admin-gated, so the helper creates an admin user.
    $rawToken = 'model-test-token-'.bin2hex(random_bytes(8));

    $user = User::create([
        'name' => 'Model Test User',
        'email' => 'model-test@example.com',
        'password' => Hash::make('password123'),
        'api_token' => hash('sha256', $rawToken),
        'is_admin' => true,
    ]);

    return [
        'user' => $user,
        'headers' => ['Authorization' => 'Bearer '.$rawToken],
    ];
}

test('test_ai_model_list_returns_all_models', function (): void {
    $auth = createAiModelTestUser();

    AiModel::create(['name' => 'Test LLM', 'type' => 'llm', 'provider' => 'openai', 'model' => 'gpt-4o', 'is_active' => true, 'sort_order' => 1]);
    AiModel::create(['name' => 'Test Embedding', 'type' => 'embedding', 'provider' => 'openai', 'model' => 'text-embedding-3-small', 'is_active' => true, 'sort_order' => 2]);

    $response = $this->withHeaders($auth['headers'])
        ->getJson('/api/settings/ai-models');

    $response->assertStatus(200);
    $response->assertJsonPath('success', true);
    expect(count($response->json('data')))->toBe(2);
});

test('test_ai_model_list_filters_by_type', function (): void {
    $auth = createAiModelTestUser();

    AiModel::create(['name' => 'Test LLM', 'type' => 'llm', 'provider' => 'openai', 'model' => 'gpt-4o', 'is_active' => true, 'sort_order' => 1]);
    AiModel::create(['name' => 'Test Embedding', 'type' => 'embedding', 'provider' => 'openai', 'model' => 'text-embedding-3-small', 'is_active' => true, 'sort_order' => 2]);

    $response = $this->withHeaders($auth['headers'])
        ->getJson('/api/settings/ai-models?type=llm');

    $response->assertStatus(200);
    expect(count($response->json('data')))->toBe(1);
    expect($response->json('data.0.type'))->toBe('llm');
});

test('test_ai_model_create_requires_authentication', function (): void {
    $response = $this->postJson('/api/settings/ai-models', [
        'name' => 'Unauthenticated Model',
        'type' => 'llm',
        'provider' => 'openai',
        'model' => 'gpt-4o',
    ]);

    $response->assertStatus(401);
});

test('test_ai_model_settings_requires_admin_role', function (): void {
    $rawToken = 'model-user-token-'.bin2hex(random_bytes(8));
    $user = User::create([
        'name' => 'Model Member User',
        'email' => 'model-member@example.com',
        'password' => Hash::make('password123'),
        'api_token' => hash('sha256', $rawToken),
        'is_admin' => false,
    ]);

    $this->assertFalse($user->isAdmin());

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawToken])
        ->getJson('/api/settings/ai-models');

    $response->assertStatus(403);
});

test('test_ai_model_create_validates_required_fields', function (): void {
    $auth = createAiModelTestUser();

    $response = $this->withHeaders($auth['headers'])
        ->postJson('/api/settings/ai-models', []);

    $response->assertStatus(422);
});

test('test_ai_model_create_stores_model', function (): void {
    $auth = createAiModelTestUser();

    $response = $this->withHeaders($auth['headers'])
        ->postJson('/api/settings/ai-models', [
            'name' => 'GPT-4o',
            'type' => 'llm',
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'is_active' => true,
            'sort_order' => 1,
        ]);

    $response->assertStatus(201);
    $response->assertJsonPath('success', true);
    $this->assertDatabaseHas('ai_models', ['name' => 'GPT-4o']);
});

test('test_ai_model_show_returns_model', function (): void {
    $auth = createAiModelTestUser();

    $model = AiModel::create(['name' => 'Test Model', 'type' => 'llm', 'provider' => 'openai', 'model' => 'gpt-4o', 'is_active' => true, 'sort_order' => 1]);

    $response = $this->withHeaders($auth['headers'])
        ->getJson("/api/settings/ai-models/{$model->id}");

    $response->assertStatus(200);
    $response->assertJsonPath('data.name', 'Test Model');
});

test('test_ai_model_show_returns_404_for_missing', function (): void {
    $auth = createAiModelTestUser();

    $response = $this->withHeaders($auth['headers'])
        ->getJson('/api/settings/ai-models/'.(string) Str::ulid());

    $response->assertStatus(404);
});

test('test_ai_model_update_modifies_model', function (): void {
    $auth = createAiModelTestUser();

    $model = AiModel::create(['name' => 'Original', 'type' => 'llm', 'provider' => 'openai', 'model' => 'gpt-4o', 'is_active' => true, 'sort_order' => 1]);

    $response = $this->withHeaders($auth['headers'])
        ->putJson("/api/settings/ai-models/{$model->id}", [
            'name' => 'Updated Name',
        ]);

    $response->assertStatus(200);
    $this->assertDatabaseHas('ai_models', ['name' => 'Updated Name']);
});

test('test_ai_model_delete_removes_model', function (): void {
    $auth = createAiModelTestUser();

    $model = AiModel::create(['name' => 'To Delete', 'type' => 'llm', 'provider' => 'openai', 'model' => 'gpt-4o', 'is_active' => true, 'sort_order' => 1]);

    $response = $this->withHeaders($auth['headers'])
        ->deleteJson("/api/settings/ai-models/{$model->id}");

    $response->assertStatus(200);
    expect(AiModel::find($model->id))->toBeNull();
});
