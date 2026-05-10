<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\UserModule\Controllers\AuthController;

Route::prefix('api/auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
});
