<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders exceptions on `api/*` routes in the ApiResponse envelope, so
 * controllers can let validation and not-found errors bubble up instead of
 * catching them one by one.
 */
final class ApiExceptionRenderer
{
    /** @var array<string, string> Model class basename → label used in "<label> not found." */
    private const MODEL_LABELS = [
        'AiModel' => 'AI model',
        'TermAlias' => 'Term alias',
        'ChatSession' => 'Session',
    ];

    /**
     * Register the render callbacks on the application's exception handler.
     *
     * @param  Exceptions  $exceptions  Handler configuration from bootstrap/app.php
     */
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (self::isApi($request)) {
                return ApiResponse::error($e->getMessage(), $e->status, $e->errors());
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (self::isApi($request)) {
                $model = $e->getPrevious() instanceof ModelNotFoundException ? $e->getPrevious()->getModel() : null;

                return ApiResponse::error(self::notFoundMessage($model), 404);
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (self::isApi($request)) {
                $status = $e->getStatusCode();
                $message = $e->getMessage() !== '' ? $e->getMessage() : (Response::$statusTexts[$status] ?? 'Error');

                return ApiResponse::error($message, $status, headers: $e->getHeaders());
            }
        });

        // Hide internals of unexpected errors in production; debug mode keeps
        // Laravel's detailed response.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (self::isApi($request) && ! config('app.debug') && ! $e instanceof HttpResponseException) {
                return ApiResponse::error('Server error.', 500);
            }
        });
    }

    private static function isApi(Request $request): bool
    {
        return $request->is('api/*');
    }

    /**
     * @param  string|null  $modelClass  Eloquent model class that was not found. Example: "Modules\\DocumentModule\\Models\\Document"
     * @return string Example: "Document not found."
     */
    private static function notFoundMessage(?string $modelClass): string
    {
        if ($modelClass === null) {
            return 'Not found.';
        }

        $basename = class_basename($modelClass);

        return (self::MODEL_LABELS[$basename] ?? $basename).' not found.';
    }
}
