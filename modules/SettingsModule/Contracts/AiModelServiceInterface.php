<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Contracts;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\SettingsModule\Models\AiModel;

/**
 * AI Model Service Interface
 *
 * Defines the contract for managing AI model configurations within the SettingsModule.
 * Covers CRUD operations for both embedding and LLM model definitions stored in the
 * ai_models table, including scoped retrieval, validation rule generation, and
 * active-model filtering.
 *
 * All implementors must respect the polymorphic type system (embedding/llm) and
 * the sort-order + is_active semantics used throughout the RAG pipeline.
 *
 * @method array getAll(?string $type = null, ?int $page = null, ?int $perPage = null) List models with optional type filtering and pagination
 * @method array getActiveModels(string $type) List only active models of a given type
 * @method AiModel find(string $id) Find a single model by ULID
 * @method AiModel create(array $data) Create a new model
 * @method AiModel update(string $id, array $data) Update an existing model
 * @method void delete(string $id) Delete a model by ULID
 * @method array getValidationRules(string $type) Get validation rules for a model type
 *
 * @throws ModelNotFoundException When find/update/delete target is missing
 */
interface AiModelServiceInterface
{
    /**
     * List all AI models with optional type filter and pagination
     *
     * When page and perPage are both provided, returns a paginated response
     * with meta information (current_page, last_page, total, etc.). Otherwise
     * returns the full result set with meta set to null.
     *
     * @param  string|null  $type  Filter by model type: "embedding" or "llm". Example: "embedding"
     * @param  int|null  $page  Page number (1-based). Required with perPage for pagination. Example: 1
     * @param  int|null  $perPage  Items per page. Required with page for pagination. Example: 20
     * @return array{data: array, meta: array|null} List of models with optional pagination meta
     *                                              Example: ["data" => [["id" => "01J...", "name" => "nomic-embed-text", ...]], "meta" => ["current_page" => 1, "last_page" => 3, "total" => 50]]
     */
    public function getAll(?string $type = null, ?int $page = null, ?int $perPage = null): array;

    /**
     * Get active models of a specific type
     *
     * Returns only models where is_active is true, ordered by sort_order then name.
     * Used by the RAG pipeline to determine which embedding or LLM model to use.
     *
     * @param  string  $type  The model type: "embedding" or "llm". Example: "embedding"
     * @return array List of active models as arrays
     *               Example: [["id" => "01J...", "name" => "nomic-embed-text", "model" => "nomic-embed-text:latest", ...]]
     */
    public function getActiveModels(string $type): array;

    /**
     * Find an AI model by its ULID
     *
     * Throws ModelNotFoundException if no model exists with the given ID.
     *
     * @param  string  $id  The ULID of the model. Example: "01JARQ3Z7KZXJ6Y6Y6Y6Y6Y6Y6"
     * @return AiModel The found model instance
     *                 Example: AiModel {id: "01J...", name: "nomic-embed-text", type: "embedding", ...}
     *
     * @throws ModelNotFoundException When no model matches the ID
     *                                Example: find("nonexistent") → ModelNotFoundException
     */
    public function find(string $id): AiModel;

    /**
     * Create a new AI model
     *
     * Mass-assigns the given data to a new AiModel instance and persists it.
     * The data array should match the model's $fillable attributes.
     *
     * @param  array  $data  Model attributes. Example: ["name" => "gpt-4o", "type" => "llm", "provider" => "openai", "model" => "gpt-4o"]
     * @return AiModel The newly created model instance
     *                 Example: AiModel {id: "01J...", name: "gpt-4o", type: "llm", ...}
     */
    public function create(array $data): AiModel;

    /**
     * Update an existing AI model
     *
     * Finds the model by ULID, applies the update, and returns a fresh instance
     * reflecting the persisted changes.
     *
     * @param  string  $id  The ULID of the model to update. Example: "01JARQ3Z7KZXJ6Y6Y6Y6Y6Y6Y6"
     * @param  array  $data  Attributes to update. Example: ["temperature" => 0.5, "is_active" => false]
     * @return AiModel The updated model instance (fresh from database)
     *                 Example: AiModel {id: "01J...", name: "gpt-4o", temperature: 0.5, ...}
     *
     * @throws ModelNotFoundException When no model matches the ID
     *                                Example: update("nonexistent", []) → ModelNotFoundException
     */
    public function update(string $id, array $data): AiModel;

    /**
     * Delete an AI model
     *
     * Finds the model by ULID and performs a soft or hard delete depending on
     * the model's configuration. Throws ModelNotFoundException if not found.
     *
     * @param  string  $id  The ULID of the model to delete. Example: "01JARQ3Z7KZXJ6Y6Y6Y6Y6Y6Y6"
     *
     * @throws ModelNotFoundException When no model matches the ID
     *                                Example: delete("nonexistent") → ModelNotFoundException
     */
    public function delete(string $id): void;

    /**
     * Get validation rules for AI model creation/update
     *
     * Returns a rules array tailored to the model type (embedding or llm).
     * Embedding models include dimensions, batch_size, and cache_ttl rules.
     * LLM models include temperature and max_context_tokens rules.
     *
     * @param  string  $type  The model type: "embedding" or "llm". Example: "embedding"
     * @return array<string, array> Validation rules keyed by attribute
     *                              Example: ["name" => ["required", "string", "max:255"], "dimensions" => ["nullable", "integer", "min:64", "max:4096"]]
     */
    public function getValidationRules(string $type): array;
}
