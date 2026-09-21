<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWithToken
{
    /**
     * Resolve the authenticated user from a bearer token.
     *
     * The presented token is one-way digested and matched against the stored
     * SHA-256 digest (never the raw token), then the token expiry is checked.
     * Non-expiring tokens (e.g. seeded records where api_token_expires_at is
     * null) remain valid; tokens issued by AuthService carry an expiry and are
     * renewed on a sliding window.
     *
     * @param  Request  $request  The incoming HTTP request. Example: request()
     * @param  Closure  $next  The next middleware/handler. Example: fn ($request) => $response
     * @return Response The response from the next layer, or a 401 JSON rejection
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return $this->unauthorized('Authentication token required.');
        }

        $user = User::where('api_token', ApiToken::hash($token))->first();

        if ($user === null) {
            return $this->unauthorized('Invalid or expired token.');
        }

        if ($user->api_token_expires_at !== null && $user->api_token_expires_at->isPast()) {
            Log::channel('stack')->warning('Expired API token rejected', ['user_id' => $user->id]);

            return $this->unauthorized('Invalid or expired token.');
        }

        // Sliding renewal: extend the lifetime when more than half has elapsed,
        // avoiding a write on every authenticated request.
        if ($user->api_token_expires_at !== null) {
            $ttl = $user->api_token_expires_at->diffInSeconds($user->api_token_expires_at->copy()->addDays(
                (int) config('rag.security.api_token_ttl_days', 30)
            ), absolute: false);

            if ($ttl > 0 && $user->api_token_expires_at->isBefore(now()->addSeconds((int) ($ttl / 2)))) {
                $user->forceFill([
                    'api_token_expires_at' => ApiToken::expiresAt(),
                ])->saveQuietly();
            }
        }

        $request->merge(['authenticated_user' => $user]);

        return $next($request);
    }

    /**
     * Build a standard 401 envelope response.
     *
     * @param  string  $message  The client-safe rejection message. Example: "Authentication token required."
     * @return Response JSON error response with 401 status
     */
    private function unauthorized(string $message): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 401);
    }
}
