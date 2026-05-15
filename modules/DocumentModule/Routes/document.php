<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\DocumentModule\Controllers\DocumentController;

Route::prefix('api/documents')->middleware('auth.token')->group(function (): void {
    Route::get('/', [DocumentController::class, 'index']);
    Route::post('/', [DocumentController::class, 'upload']);
    Route::get('{id}', [DocumentController::class, 'show'])->whereUlid('id');
    Route::get('{id}/status', [DocumentController::class, 'status'])->whereUlid('id');
    Route::post('{id}/retry', [DocumentController::class, 'retry'])->whereUlid('id');
    Route::delete('{id}', [DocumentController::class, 'destroy'])->whereUlid('id');
});
