<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\SettingsModule\Controllers\AiModelController;
use Modules\SettingsModule\Controllers\TermAliasController;

Route::prefix('api/settings')->middleware('auth.token')->group(function (): void {
    Route::get('ai-models', [AiModelController::class, 'index']);
    Route::post('ai-models', [AiModelController::class, 'store']);
    Route::get('ai-models/{id}', [AiModelController::class, 'show'])->whereUlid('id');
    Route::put('ai-models/{id}', [AiModelController::class, 'update'])->whereUlid('id');
    Route::delete('ai-models/{id}', [AiModelController::class, 'destroy'])->whereUlid('id');

    Route::get('term-aliases', [TermAliasController::class, 'index']);
    Route::post('term-aliases', [TermAliasController::class, 'store']);
    Route::get('term-aliases/{id}', [TermAliasController::class, 'show'])->whereUlid('id');
    Route::put('term-aliases/{id}', [TermAliasController::class, 'update'])->whereUlid('id');
    Route::delete('term-aliases/{id}', [TermAliasController::class, 'destroy'])->whereUlid('id');
});
