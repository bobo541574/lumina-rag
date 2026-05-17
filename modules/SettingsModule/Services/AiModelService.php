<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\SettingsModule\Contracts\AiModelServiceInterface;
use Modules\SettingsModule\Models\AiModel;

/**
 * AI Model Service
 *
 * Concrete implementation of AiModelServiceInterface providing full CRUD
 * operations on the ai_models table. Handles both embedding and LLM model
 * types with type-specific validation rule generation.
 *
 * Models are ordered by sort_order then name for consistent listing.
 * Pagination is optional — when pagination params are omitted, all matching
 * records are returned at once.
 *
 * @implements AiModelServiceInterface
 */
class AiModelService implements AiModelServiceInterface
{
    /**
     * List all AI models with optional type filter and pagination
     *
     * Queries the AiModel table ordered by sort_order then name. When type
     * is provided, filters by that type. When both page and perPage are given,
     * returns a paginated response with standard Laravel pagination meta.
     *
     * @param  string|null  $type  Filter by model type: "embedding" or "llm". Example: "embedding"
     * @param  int|null  $page  Page number (1-based). Example: 1
     * @param  int|null  $perPage  Items per page. Example: 20
     * @return array{data: array, meta: array|null} List of models with optional pagination meta
     *                                              Example: ["data" => [["id" => "01J...", "name" => "nomic-embed-text", ...]], "meta" => ["current_page" => 1, "last_page" => 3, "total" => 50]]
     */
    public function getAll(?string $type = null, ?int $page = null, ?int $perPage = null): array
    {
        $query = AiModel::orderBy('sort_order')->orderBy('name');

        if ($type !== null) {
            $query->where('type', $type);
        }

        if ($page !== null && $perPage !== null) {
            $result = $query->paginate($perPage, ['*'], 'page', $page)->toArray();

            return [
                'data' => $result['data'],
                'meta' => [
                    'current_page' => $result['current_page'],
                    'last_page' => $result['last_page'],
                    'per_page' => $result['per_page'],
                    'total' => $result['total'],
                    'from' => $result['from'],
                    'to' => $result['to'],
                ],
            ];
        }

        return [
            'data' => $query->get()->toArray(),
            'meta' => null,
        ];
    }

    /**
     * Get active models of a specific type
     *
     * Returns only models where is_active is true, scoped by the given type.
     * Ordered by sort_order then name. Used by the RAG pipeline to resolve
     * which embedding or LLM model is currently active for a given type.
     *
     * @param  string  $type  The model type: "embedding" or "llm". Example: "embedding"
     * @return array List of active models as arrays
     *               Example: [["id" => "01J...", "name" => "nomic-embed-text", "model" => "nomic-embed-text:latest", ...]]
     */
    public function getActiveModels(string $type): array
    {
        return AiModel::active()
            ->where('type', $type)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    /**
     * Find an AI model by its ULID
     *
     * Delegates to Eloquent's findOrFail which throws ModelNotFoundException
     * when no record matches the given ULID.
     *
     * @param  string  $id  The ULID of the model. Example: "01JARQ3Z7KZXJ6Y6Y6Y6Y6Y6Y6"
     * @return AiModel The found model instance
     *                 Example: AiModel {id: "01J...", name: "nomic-embed-text", type: "embedding", ...}
     *
     * @throws ModelNotFoundException When no model matches the ID
     *                                Example: find("nonexistent") → ModelNotFoundException
     */
    public function find(string $id): AiModel
    {
        return AiModel::findOrFail($id);
    }

    /**
     * Create a new AI model
     *
     * Mass-assigns the given data using Eloquent's create method. All
     * attributes should be in the model's $fillable array.
     *
     * @param  array  $data  Model attributes. Example: ["name" => "gpt-4o", "type" => "llm", "provider" => "openai", "model" => "gpt-4o"]
     * @return AiModel The newly created model instance
     *                 Example: AiModel {id: "01J...", name: "gpt-4o", type: "llm", ...}
     */
    public function create(array $data): AiModel
    {
        return AiModel::create($data);
    }

    /**
     * Update an existing AI model
     *
     * Finds the model by ULID, applies the update, and returns a fresh
     * instance from the database. The fresh() call ensures cast attributes
     * (booleans, integers, arrays) are properly hydrated.
     *
     * @param  string  $id  The ULID of the model to update. Example: "01JARQ3Z7KZXJ6Y6Y6Y6Y6Y6Y6"
     * @param  array  $data  Attributes to update. Example: ["temperature" => 0.5, "is_active" => false]
     * @return AiModel The updated model instance (fresh from database)
     *                 Example: AiModel {id: "01J...", name: "gpt-4o", temperature: 0.5, ...}
     *
     * @throws ModelNotFoundException When no model matches the ID
     *                                Example: update("nonexistent", []) → ModelNotFoundException
     */
    public function update(string $id, array $data): AiModel
    {
        $model = $this->find($id);
        $model->update($data);

        return $model->fresh();
    }

    /**
     * Delete an AI model
     *
     * Finds the model by ULID and calls delete() on the instance. Soft deletes
     * are respected if the model uses the SoftDeletes trait.
     *
     * @param  string  $id  The ULID of the model to delete. Example: "01JARQ3Z7KZXJ6Y6Y6Y6Y6Y6Y6"
     *
     * @throws ModelNotFoundException When no model matches the ID
     *                                Example: delete("nonexistent") → ModelNotFoundException
     */
    public function delete(string $id): void
    {
        $model = $this->find($id);
        $model->delete();
    }

    /**
     * Get validation rules for AI model creation/update
     *
     * Builds a comprehensive validation rules array based on the model type.
     * Base rules apply to all models (name, type, provider, etc.). Type-specific
     * rules are added for embedding (dimensions, batch_size, cache_ttl) and
     * LLM (temperature, max_context_tokens) models.
     *
     * @param  string  $type  The model type: "embedding" or "llm". Example: "embedding"
     * @return array<string, array> Validation rules keyed by attribute name
     *                              Example: ["name" => ["required", "string", "max:255"], "dimensions" => ["nullable", "integer", "min:64", "max:4096"]]
     */
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
