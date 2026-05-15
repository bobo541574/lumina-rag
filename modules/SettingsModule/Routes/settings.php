<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\SettingsModule\Controllers\AiModelController;
use Modules\SettingsModule\Controllers\SettingsController;

Route::prefix('api/settings')->middleware('auth.token')->group(function (): void {
    Route::get('/', [SettingsController::class, 'index']);
    Route::put('bulk', [SettingsController::class, 'bulkUpdate']);
    Route::put('{key}', [SettingsController::class, 'update']);
    Route::delete('{key}', [SettingsController::class, 'destroy']);

    // AI model management
    Route::get('ai-models', [AiModelController::class, 'index']);
    Route::post('ai-models', [AiModelController::class, 'store']);
    Route::get('ai-models/{id}', [AiModelController::class, 'show'])->whereUlid('id');
    Route::put('ai-models/{id}', [AiModelController::class, 'update'])->whereUlid('id');
    Route::delete('ai-models/{id}', [AiModelController::class, 'destroy'])->whereUlid('id');
});
