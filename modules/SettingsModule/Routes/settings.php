<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\SettingsModule\Controllers\SettingsController;

Route::prefix('api/settings')->middleware('auth.token')->group(function (): void {
    Route::get('/', [SettingsController::class, 'index']);
    Route::put('bulk', [SettingsController::class, 'bulkUpdate']);
    Route::put('{key}', [SettingsController::class, 'update']);
    Route::delete('{key}', [SettingsController::class, 'destroy']);
});
