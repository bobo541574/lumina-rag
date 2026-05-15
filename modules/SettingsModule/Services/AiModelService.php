<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Services;

use Modules\SettingsModule\Models\AiModel;

class AiModelService
{
    public function getAll(?string $type = null): array
    {
        $query = AiModel::orderBy('sort_order')->orderBy('name');

        if ($type !== null) {
            $query->where('type', $type);
        }

        return $query->get()->toArray();
    }

    public function getActiveModels(string $type): array
    {
        return AiModel::active()
            ->where('type', $type)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    public function find(string $id): AiModel
    {
        return AiModel::findOrFail($id);
    }

    public function create(array $data): AiModel
    {
        return AiModel::create($data);
    }

    public function update(string $id, array $data): AiModel
    {
        $model = $this->find($id);
        $model->update($data);

        return $model->fresh();
    }

    public function delete(string $id): void
    {
        $model = $this->find($id);
        $model->delete();
    }

    public function getValidationRules(string $type): array
    {
        $providers = ['openai', 'ollama'];

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:embedding,llm'],
            'provider' => ['required', 'string', 'in:'.implode(',', $providers)],
            'model' => ['required', 'string', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'base_url' => ['nullable', 'string', 'max:500'],
            'collection' => ['nullable', 'string', 'max:100'],
            'timeout' => ['nullable', 'integer', 'min:1', 'max:300'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:2000'],
            'settings' => ['nullable', 'array'],
        ];

        if ($type === 'embedding') {
            $rules['dimensions'] = ['nullable', 'integer', 'min:64', 'max:4096'];
            $rules['batch_size'] = ['nullable', 'integer', 'min:1', 'max:500'];
            $rules['cache_ttl'] = ['nullable', 'integer', 'min:60', 'max:604800'];
        }

        if ($type === 'llm') {
            $rules['temperature'] = ['nullable', 'numeric', 'min:0', 'max:2'];
            $rules['max_context_tokens'] = ['nullable', 'integer', 'min:256', 'max:128000'];
        }

        return $rules;
    }
}
