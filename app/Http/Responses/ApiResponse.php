<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * Builds the API response envelope: { success, message, data, meta, errors }.
 *
 * Keys without a value are omitted, so a success response carries only the
 * parts the endpoint provides and an error carries `errors` only when there
 * are field-level validation messages.
 */
final class ApiResponse
{
    /**
     * @param  mixed  $data  Response payload. Example: ["id" => "01J..."]
     * @param  string|null  $message  Human-readable message. Example: "Document deleted successfully."
     * @param  int  $status  HTTP status. Example: 201
     * @param  array|null  $meta  Pagination or other metadata; omitted when null or empty. Example: ["total" => 42]
     */
    public static function success(mixed $data = null, ?string $message = null, int $status = 200, ?array $meta = null): JsonResponse
    {
        $body = ['success' => true];

        if ($message !== null) {
            $body['message'] = $message;
        }
        if ($data !== null) {
            $body['data'] = $data;
        }
        if ($meta !== null && $meta !== []) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status);
    }

    /**
     * @param  string  $message  Human-readable, user-safe message. Example: "Document not found."
     * @param  int  $status  HTTP status. Example: 404
     * @param  array  $errors  Field-level validation messages. Example: ["title" => ["The title field is required."]]
     * @param  array  $headers  Extra response headers. Example: ["Retry-After" => "60"]
     */
    public static function error(string $message, int $status, array $errors = [], array $headers = []): JsonResponse
    {
        $body = ['success' => false, 'message' => $message];

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status, $headers);
    }

    /**
     * Paginated list response from a service's paginate-to-array result.
     *
     * @param  array  $page  Result with data, current_page, last_page, per_page, total, from, to.
     */
    public static function paginated(array $page): JsonResponse
    {
        return self::success($page['data'], meta: [
            'current_page' => $page['current_page'],
            'last_page' => $page['last_page'],
            'per_page' => $page['per_page'],
            'total' => $page['total'],
            'from' => $page['from'],
            'to' => $page['to'],
        ]);
    }
}
