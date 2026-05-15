<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\SettingsModule\Services\SettingsService;

class SettingsController extends Controller
{
    private SettingsService $settings;

    public function __construct(SettingsService $settings)
    {
        $this->settings = $settings;
    }

    public function index(): JsonResponse
    {
        $settings = $this->settings->getAll();
        $definitions = $this->settings->getDefinitions();

        return response()->json([
            'success' => true,
            'data' => [
                'settings' => $settings,
                'definitions' => $definitions,
            ],
        ]);
    }

    public function update(string $key): JsonResponse
    {
        $value = request()->input('value');
        $type = request()->input('type');

        try {
            $setting = $this->settings->set($key, $value, $type);

            return response()->json([
                'success' => true,
                'message' => 'Setting updated successfully.',
                'data' => $setting,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function destroy(string $key): JsonResponse
    {
        $this->settings->delete($key);

        return response()->json([
            'success' => true,
            'message' => 'Setting reset to default.',
        ]);
    }

    public function bulkUpdate(): JsonResponse
    {
        $settings = request()->input('settings', []);

        if (! is_array($settings) || $settings === []) {
            return response()->json([
                'success' => false,
                'message' => 'No settings provided.',
            ], 422);
        }

        $results = [];

        foreach ($settings as $key => $data) {
            try {
                $value = $data['value'] ?? null;
                $type = $data['type'] ?? null;
                $setting = $this->settings->set($key, $value, $type);
                $results[$key] = ['success' => true, 'id' => $setting->id];
            } catch (\Throwable $e) {
                $results[$key] = ['success' => false, 'message' => $e->getMessage()];
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Settings updated.',
            'data' => $results,
        ]);
    }
}
