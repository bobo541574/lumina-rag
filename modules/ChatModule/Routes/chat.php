<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ChatModule\Controllers\ChatController;

Route::prefix('api/chat')->middleware('auth.token')->group(function (): void {
    Route::post('/', [ChatController::class, 'ask']);
    Route::get('sessions', [ChatController::class, 'sessions']);
    Route::get('sessions/{id}', [ChatController::class, 'showSession'])->whereUlid('id');
    Route::delete('sessions/{id}', [ChatController::class, 'destroySession'])->whereUlid('id');
});
