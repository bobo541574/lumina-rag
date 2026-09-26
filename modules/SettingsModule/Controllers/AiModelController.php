<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Controllers;

use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\SettingsModule\Contracts\AiModelServiceInterface;

/**
 * AI Model Controller
 *
 * Handles HTTP requests for CRUD operations on AI model configurations.
 * Provides RESTful endpoints for listing, creating, reading, updating, and
 * deleting embedding and LLM models stored in the ai_models table.
 *
 * All responses use the standard { success, message, data, errors } envelope.
 * Validation rules are dynamically generated based on the model type
 * (embedding vs. llm).
 */
class AiModelController extends Controller
{
    private AiModelServiceInterface $modelService;

    /**
     * @param  AiModelServiceInterface  $modelService  Service for AI model CRUD operations. Example: $app->make(AiModelServiceInterface::class)
     */
    public function __construct(AiModelServiceInterface $modelService)
    {
        $this->modelService = $modelService;
    }

    /**
     * List all AI models
     *
     * Accepts optional query parameters: type (embedding/llm) for filtering,
     * page and per_page for pagination. Per_page is capped by the
     * rag.pagination.max_per_page config value when provided.
     *
     * @param  Request  $request  HTTP request with optional query params. Example: Request with ?type=embedding&page=1&per_page=20
     * @return JsonResponse JSON response with data array and optional pagination meta
     *                      Example: {"success": true, "data": [...], "meta": {"current_page": 1, "last_page": 3, "total": 50}}
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->integer('per_page') ?: null;
        $result = $this->modelService->getAll(
            $request->input('type'),
            $request->integer('page') ?: null,
            $perPage !== null ? min($perPage, (int) config('rag.pagination.max_per_page')) : null,
        );

        return ApiResponse::success($result['data'], meta: $result['meta']);
    }

    /**
     * Create a new AI model
     *
     * Validates the request data against type-specific rules (embedding or llm),
     * delegates creation to the service, and returns the newly created model.
     * Returns 201 on success, 422 on validation or processing errors.
     *
     * @param  Request  $request  HTTP request with model attributes. Example: Request with body {"name": "gpt-4o", "type": "llm", "provider": "openai", "model": "gpt-4o"}
     * @return JsonResponse JSON response with created model data
     *                      Example: {"success": true, "message": "AI model created successfully.", "data": {"id": "01J...", "name": "gpt-4o", ...}}
     *
     * @throws ValidationException Rendered as 422 with field errors by ApiExceptionRenderer
     * @throws \Throwable Persistence failures are logged and returned as a generic 422
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(
            $this->modelService->getValidationRules($request->input('type', 'embedding')),
        );

        try {
            $model = $this->modelService->create($data);
        } catch (\Throwable $e) {
            Log::channel('security')->error('ai_model.store_failed', ['error' => $e->getMessage()]);

            return ApiResponse::error('Unable to create the AI model.', 422);
        }

        Log::channel('security')->info('ai_model.created', [
            'model_id' => $model->id,
            'name' => $model->name,
            'type' => $model->type,
            'provider' => $model->provider,
        ]);

        return ApiResponse::success($model->toArray(), 'AI model created successfully.', 201);
    }

    /**
     * Show a single AI model
     *
     * Returns the full model data for the given ULID. Returns 404 if the
     * model does not exist.
     *
     * @param  string  $id  The ULID of the model. Example: "01JARQ3Z7KZXJ6Y6Y6Y6Y6Y6Y6"
     * @return JsonResponse JSON response with model data
     *                      Example: {"success": true, "data": {"id": "01J...", "name": "nomic-embed-text", ...}}
     *
     * @throws ModelNotFoundException When no model matches the ID (rendered as 404 by ApiExceptionRenderer)
     */
    public function show(string $id): JsonResponse
    {
        return ApiResponse::success($this->modelService->find($id)->toArray());
    }

    /**
     * Update an existing AI model
     *
     * Fetches the existing model to determine its type, then uses the
     * appropriate validation rules with 'sometimes' prefix so only provided
     * fields are validated. Returns the updated model on success.
     *
     * @param  Request  $request  HTTP request with attributes to update. Example: Request with body {"temperature": 0.5, "is_active": false}
     * @param  string  $id  The ULID of the model to update. Example: "01JARQ3Z7KZXJ6Y6Y6Y6Y6Y6Y6"
     * @return JsonResponse JSON response with updated model data
     *                      Example: {"success": true, "message": "AI model updated successfully.", "data": {"id": "01J...", "temperature": 0.5, ...}}
     *
     * @throws ModelNotFoundException When no model matches the ID (rendered as 404 by ApiExceptionRenderer)
     * @throws \Throwable Persistence failures are logged and returned as a generic 422
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $rules = array_map(
            fn (array $rule): array => ['sometimes', ...$rule],
            $this->modelService->getValidationRules($this->modelService->find($id)->type),
        );
        $data = $request->validate($rules);

        try {
            $model = $this->modelService->update($id, $data);
        } catch (\Throwable $e) {
            Log::channel('security')->error('ai_model.update_failed', ['error' => $e->getMessage()]);

            return ApiResponse::error('Unable to update the AI model.', 422);
        }

        Log::channel('security')->info('ai_model.updated', [
            'model_id' => $id,
            'changed' => array_keys($data),
        ]);

        return ApiResponse::success($model->toArray(), 'AI model updated successfully.');
    }

    /**
     * Delete an AI model
     *
     * Removes the model identified by the given ULID. Returns 200 on success,
     * 404 if not found, 500 on unexpected errors.
     *
     * @param  string  $id  The ULID of the model to delete. Example: "01JARQ3Z7KZXJ6Y6Y6Y6Y6Y6Y6"
     * @return JsonResponse JSON response with deletion confirmation
     *                      Example: {"success": true, "message": "AI model deleted successfully."}
     *
     * @throws ModelNotFoundException When no model matches the ID (rendered as 404 by ApiExceptionRenderer)
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $this->modelService->delete($id);
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('security')->error('ai_model.delete_failed', [
                'model_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return ApiResponse::error('Unable to delete the AI model.', 500);
        }

        Log::channel('security')->info('ai_model.deleted', ['model_id' => $id]);

        return ApiResponse::success(message: 'AI model deleted successfully.');
    }
}
