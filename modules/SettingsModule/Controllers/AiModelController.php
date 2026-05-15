<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\SettingsModule\Services\AiModelService;

class AiModelController extends Controller
{
    private AiModelService $modelService;

    public function __construct(AiModelService $modelService)
    {
        $this->modelService = $modelService;
    }

    public function index(Request $request): JsonResponse
    {
        $type = $request->input('type'); // 'embedding' or 'llm' or null for all

        return response()->json([
            'success' => true,
            'data' => $this->modelService->getAll($type),
        ]);
    }

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
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function show(string $id): JsonResponse
    {
        try {
            $model = $this->modelService->find($id);

            return response()->json([
                'success' => true,
                'data' => $model->toArray(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'AI model not found.',
            ], 404);
        }
    }

    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $existing = $this->modelService->find($id);
            $type = $existing->type;
            $rules = $this->modelService->getValidationRules($type);

            // Make fields optional for update
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
        } catch (\Throwable $e) {
            $status = $e instanceof ModelNotFoundException ? 404 : 422;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $this->modelService->delete($id);

            return response()->json([
                'success' => true,
                'message' => 'AI model deleted successfully.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'AI model not found.',
            ], 404);
        }
    }
}
