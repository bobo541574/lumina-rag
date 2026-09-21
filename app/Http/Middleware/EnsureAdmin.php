<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensure the authenticated user holds the admin role.
 *
 * Must run after AuthenticateWithToken (the resolver attaches the user to the
 * request as `authenticated_user`). Grants access only when that user carries
 * the is_admin flag (ISO 27002:5.15, 8.2 privileged access control).
 */
class EnsureAdmin
{
    /**
     * Reject non-admin requests with a 403 envelope.
     *
     * @param  Request  $request  The incoming HTTP request. Example: request()
     * @param  Closure  $next  The next middleware/handler. Example: fn ($request) => $response
     * @return Response The next layer's response, or a 403 JSON rejection
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->input('authenticated_user');

        if (! $user instanceof User || ! $user->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Administrator privileges required.',
            ], 403);
        }

        return $next($request);
    }
}
