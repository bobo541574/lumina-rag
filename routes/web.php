<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/api/health', function (): array {
    $dbOk = false;
    $pgvectorOk = false;
    $cacheOk = false;
    $queueOk = false;

    $dbMessage = null;
    $pgvectorMessage = null;
    $cacheMessage = null;
    $queueMessage = null;

    try {
        DB::connection()->getPdo();
        $dbOk = true;

        $result = DB::select('SELECT extname FROM pg_extension WHERE extname = ?', ['vector']);
        if (count($result) > 0) {
            $pgvectorOk = true;
        } else {
            $pgvectorMessage = 'pgvector extension not installed';
        }
    } catch (Throwable $e) {
        $dbMessage = $e->getMessage();
        $pgvectorMessage = 'database unavailable';
    }

    try {
        Cache::store()->set('health_check', true, 10);
        $cacheOk = Cache::store()->get('health_check') === true;
        Cache::store()->forget('health_check');
        if (! $cacheOk) {
            $cacheMessage = 'cache write/read mismatch';
        }
    } catch (Throwable $e) {
        $cacheMessage = $e->getMessage();
    }

    try {
        $queueConfig = config('queue.default', 'sync');
        $queueOk = $queueConfig !== 'sync' || true;
        if ($queueConfig === 'sync') {
            $queueMessage = 'running in sync mode';
        }
    } catch (Throwable $e) {
        $queueMessage = $e->getMessage();
    }

    $status = $dbOk ? 'ok' : 'degraded';

    return [
        'status' => $status,
        'app' => config('app.name'),
        'env' => config('app.env'),
        'debug' => config('app.debug'),
        'checks' => [
            'database' => [
                'status' => $dbOk ? 'connected' : 'unavailable',
                'message' => $dbMessage,
            ],
            'pgvector' => [
                'status' => $pgvectorOk ? 'available' : 'unavailable',
                'message' => $pgvectorMessage,
            ],
            'cache' => [
                'status' => $cacheOk ? 'connected' : 'unavailable',
                'message' => $cacheMessage,
            ],
            'queue' => [
                'driver' => config('queue.default', 'sync'),
                'status' => $queueOk ? 'configured' : 'unavailable',
                'message' => $queueMessage,
            ],
        ],
        'timestamp' => now()->toIso8601String(),
    ];
});

Route::fallback(function () {
    return view('welcome');
});
