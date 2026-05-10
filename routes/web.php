<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/api/health', function (): array {
    $dbOk = false;

    try {
        DB::connection()->getPdo();
        $dbOk = true;
    } catch (Throwable) {
        // database not available
    }

    return [
        'status' => 'ok',
        'app' => config('app.name'),
        'env' => config('app.env'),
        'debug' => config('app.debug'),
        'database' => $dbOk ? 'connected' : 'unavailable',
        'timestamp' => now()->toIso8601String(),
    ];
});

Route::fallback(function () {
    return view('welcome');
});
