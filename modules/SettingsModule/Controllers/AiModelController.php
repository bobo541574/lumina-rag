<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
        $type = $request->input('type');
        $perPage = $request->integer('per_page') ?: null;
        $result = $this->modelService->getAll(
            $type,
            $request->integer('page') ?: null,
            $perPage !== null ? min($perPage, (int) config('rag.pagination.max_per_page')) : null,
        );

        return response()->json([
            'success' => true,
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
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
     * @throws ModelNotFoundException When a referenced model is not found
     * @throws \Throwable On validation or processing failures (caught and returned as 422)
     */
    public function store(Request $request): JsonResponse
    {
        $type = $request->input('type', 'embedding');
        $rules = $this->modelService->getValidationRules($type);

        $data = $request->validate($rules);

        try {
            $model = $this->modelService->create($data);

            return response()->json([
                'success' => true,
                'message' => 'AI model created successfully.',
                'data' => $model->toArray(),
            ], 201);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Referenced model not found.',
            ], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Show a single AI model
     *
     * Returns the full model data for the given ULID. Returns 404 if the
     * model does not exist, 500 on unexpected errors.
     *
     * @param  string  $id  The ULID of the model. Example: "01JARQ3Z7KZXJ6Y6Y6Y6Y6Y6Y6"
     * @return JsonResponse JSON response with model data
     *                      Example: {"success": true, "data": {"id": "01J...", "name": "nomic-embed-text", ...}}
     *
     * @throws ModelNotFoundException When no model matches the ID (caught and returned as 404)
     */
    public function show(string $id): JsonResponse
    {
        try {
            $model = $this->modelService->find($id);

            return response()->json([
                'success' => true,
                'data' => $model->toArray(),
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'AI model not found.',
            ], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
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
     * @throws ModelNotFoundException When no model matches the ID (caught and returned as 404)
     * @throws \Throwable On validation or processing failures (caught and returned as 422)
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $existing = $this->modelService->find($id);
            $type = $existing->type;
            $rules = $this->modelService->getValidationRules($type);

            foreach ($rules as $key => $rule) {
                if (is_array($rule)) {
                    $rules[$key] = array_merge(['sometimes'], $rule);
                }
            }

            $data = $request->validate($rules);
            $model = $this->modelService->update($id, $data);

            return response()->json([
                'success' => true,
                'message' => 'AI model updated successfully.',
                'data' => $model->toArray(),
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'AI model not found.',
            ], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
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
     * @throws ModelNotFoundException When no model matches the ID (caught and returned as 404)
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $this->modelService->delete($id);

            return response()->json([
                'success' => true,
                'message' => 'AI model deleted successfully.',
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'AI model not found.',
            ], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
