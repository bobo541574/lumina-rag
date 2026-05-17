<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\SettingsModule\Contracts\TermAliasServiceInterface;

/**
 * Term Alias Controller
 *
 * Handles HTTP requests for CRUD operations on term alias mappings.
 * Provides RESTful endpoints for managing alias→canonical mappings used
 * during RAG search expansion to support multilingual and variant-aware
 * query matching.
 *
 * All responses use the standard { success, message, data, errors } envelope.
 * Aliases are categorized by type: project, technical, or general.
 */
class TermAliasController extends Controller
{
    private TermAliasServiceInterface $aliasService;

    /**
     * @param  TermAliasServiceInterface  $aliasService  Service for term alias CRUD and expansion. Example: $app->make(TermAliasServiceInterface::class)
     */
    public function __construct(TermAliasServiceInterface $aliasService)
    {
        $this->aliasService = $aliasService;
    }

    /**
     * List all term aliases
     *
     * Accepts optional query parameters: type (project/technical/general)
     * for filtering, page and per_page for pagination. Per_page is capped
     * by the rag.pagination.max_per_page config value when provided.
     *
     * @param  Request  $request  HTTP request with optional query params. Example: Request with ?type=project&page=1&per_page=20
     * @return JsonResponse JSON response with data array and optional pagination meta
     *                      Example: {"success": true, "data": [...], "meta": {"current_page": 1, "last_page": 3, "total": 50}}
     */
    public function index(Request $request): JsonResponse
    {
        $type = $request->input('type');
        $perPage = $request->integer('per_page') ?: null;
        $result = $this->aliasService->getAll(
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
     * Create a new term alias
     *
     * Validates the request data (type, alias, canonical) and delegates
     * creation to the service. The service automatically clears the alias
     * cache so the new mapping is immediately available for search.
     * Returns 201 on success, 422 on validation or processing errors.
     *
     * @param  Request  $request  HTTP request with alias attributes. Example: Request with body {"type": "project", "alias": "အိုရီယွန်", "canonical": "Orion"}
     * @return JsonResponse JSON response with created alias data
     *                      Example: {"success": true, "message": "Term alias created successfully.", "data": {"id": "01J...", "alias": "အိုရီယွန်", "canonical": "Orion", ...}}
     *
     * @throws \Throwable On validation or processing failures (caught and returned as 422)
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:project,technical,general'],
            'alias' => ['required', 'string', 'max:255'],
            'canonical' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        try {
            $alias = $this->aliasService->create($data);

            return response()->json([
                'success' => true,
                'message' => 'Term alias created successfully.',
                'data' => $alias->toArray(),
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Show a single term alias
     *
     * Returns the full alias data for the given ULID. Returns 404 if the
     * alias does not exist.
     *
     * @param  string  $id  The ULID of the alias. Example: "01JARQ3Z7KZXJ6Y6Y6Y6Y6Y6Y6"
     * @return JsonResponse JSON response with alias data
     *                      Example: {"success": true, "data": {"id": "01J...", "alias": "အိုရီယွန်", "canonical": "Orion", ...}}
     *
     * @throws ModelNotFoundException When no alias matches the ID (caught and returned as 404)
     */
    public function show(string $id): JsonResponse
    {
        try {
            $alias = $this->aliasService->find($id);

            return response()->json([
                'success' => true,
                'data' => $alias->toArray(),
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Term alias not found.',
            ], 404);
        }
    }

    /**
     * Update an existing term alias
     *
     * Verifies the alias exists, then validates and applies the update.
     * The service automatically clears the cache so updated mappings are
     * immediately reflected in search results.
     *
     * @param  Request  $request  HTTP request with attributes to update. Example: Request with body {"canonical": "NewOrion", "is_active": false}
     * @param  string  $id  The ULID of the alias to update. Example: "01JARQ3Z7KZXJ6Y6Y6Y6Y6Y6Y6"
     * @return JsonResponse JSON response with updated alias data
     *                      Example: {"success": true, "message": "Term alias updated successfully.", "data": {"id": "01J...", "canonical": "NewOrion", ...}}
     *
     * @throws ModelNotFoundException When no alias matches the ID (caught and returned as 404)
     * @throws \Throwable On validation or processing failures (caught and returned as 422)
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $this->aliasService->find($id);

            $data = $request->validate([
                'type' => ['sometimes', 'string', 'in:project,technical,general'],
                'alias' => ['sometimes', 'string', 'max:255'],
                'canonical' => ['sometimes', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:500'],
                'is_active' => ['nullable', 'boolean'],
            ]);

            $alias = $this->aliasService->update($id, $data);

            return response()->json([
                'success' => true,
                'message' => 'Term alias updated successfully.',
                'data' => $alias->toArray(),
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Term alias not found.',
            ], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Delete a term alias
     *
     * Verifies the alias exists, then deletes it through the service.
     * The service automatically clears the cache to prevent stale mappings
     * from being used in subsequent searches.
     *
     * @param  string  $id  The ULID of the alias to delete. Example: "01JARQ3Z7KZXJ6Y6Y6Y6Y6Y6Y6"
     * @return JsonResponse JSON response with deletion confirmation
     *                      Example: {"success": true, "message": "Term alias deleted successfully."}
     *
     * @throws ModelNotFoundException When no alias matches the ID (caught and returned as 404)
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $this->aliasService->find($id);
            $this->aliasService->delete($id);

            return response()->json([
                'success' => true,
                'message' => 'Term alias deleted successfully.',
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Term alias not found.',
            ], 404);
        }
    }
}
