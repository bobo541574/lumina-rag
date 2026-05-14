<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\UserModule\Controllers\AuthController;

Route::prefix('api/auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,60');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth.token');
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth.token');
});
