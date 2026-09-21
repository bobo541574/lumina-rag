<?php

declare(strict_types=1);

namespace Modules\UserModule\Services;

use App\Models\User;
use App\Support\ApiToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Modules\UserModule\Contracts\AuthServiceInterface;

/**
 * Auth Service
 *
 * Handles user authentication logic including registration, login, logout, and
 * token-based user retrieval. Tokens are generated, returned to the client
 * exactly once, and stored only as a SHA-256 digest with an expiry. Sensitive
 * authentication events are written to the security audit log.
 */
class AuthService implements AuthServiceInterface
{
    /**
     * Register a new user
     *
     * Creates a user in the database, hashes the password, and issues a random
     * bearer token (stored as a digest). Duplicate emails are rejected with a
     * generic message to prevent account enumeration.
     *
     * @param  array  $data  User registration data. Example: ["name" => "Jane", "email" => "jane@example.com", "password" => "password"]
     * @return array The registered user details and token. Example: ["user" => ["id" => "...", "name" => "..."], "token" => "abc..."]
     *
     * @throws \InvalidArgumentException If the email is already taken. Example: throw new \InvalidArgumentException("Unable to register")
     */
    public function register(array $data): array
    {
        $existing = User::where('email', $data['email'])->first();
        if ($existing !== null) {
            throw new \InvalidArgumentException('Unable to register with these details.');
        }

        $token = ApiToken::make();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'api_token' => ApiToken::hash($token),
            'api_token_expires_at' => ApiToken::expiresAt(),
        ]);

        Log::channel('security')->info('user.registered', [
            'user_id' => $user->id,
            'email' => $data['email'],
        ]);

        return [
            'user' => $user->only(['id', 'name', 'email']),
            'token' => $token,
        ];
    }

    /**
     * Authenticate a user
     *
     * Verifies email and password, and issues a fresh token (stored as a
     * digest) upon successful login. Failures and successes are audited.
     *
     * @param  string  $email  User's email. Example: "test@example.com"
     * @param  string  $password  User's password. Example: "secret"
     * @return array The authenticated user and new token. Example: ["user" => [...], "token" => "..."]
     *
     * @throws \InvalidArgumentException If credentials are invalid. Example: throw new \InvalidArgumentException("Invalid credentials")
     */
    public function login(string $email, string $password): array
    {
        $user = User::where('email', $email)->first();

        if ($user === null || ! Hash::check($password, (string) $user->password)) {
            Log::channel('security')->warning('user.login_failed', [
                'email' => $email,
            ]);

            throw new \InvalidArgumentException('Invalid email or password.');
        }

        $token = ApiToken::make();
        $user->update([
            'api_token' => ApiToken::hash($token),
            'api_token_expires_at' => ApiToken::expiresAt(),
        ]);

        Log::channel('security')->info('user.login', [
            'user_id' => $user->id,
        ]);

        return [
            'user' => $user->only(['id', 'name', 'email']),
            'token' => $token,
        ];
    }

    /**
     * Logout a user
     *
     * Clears the API token for the user associated with the given token.
     *
     * @param  string  $token  The raw API token to clear. Example: "80-char-hex-token"
     */
    public function logout(string $token): void
    {
        $user = User::where('api_token', ApiToken::hash($token))->first();
        if ($user !== null) {
            $user->update(['api_token' => null, 'api_token_expires_at' => null]);

            Log::channel('security')->info('user.logout', [
                'user_id' => $user->id,
            ]);
        }
    }

    /**
     * Get user by token
     *
     * Finds a user record by its API token digest and checks the token expiry.
     *
     * @param  string  $token  The raw API token to search for. Example: "80-char-hex-token"
     * @return array|null User details or null if not found. Example: ["id" => "...", "name" => "..."]
     */
    public function getUserByToken(string $token): ?array
    {
        $user = User::where('api_token', ApiToken::hash($token))->first();

        if ($user === null) {
            return null;
        }

        if ($user->api_token_expires_at !== null && $user->api_token_expires_at->isPast()) {
            return null;
        }

        return $user->only(['id', 'name', 'email']);
    }
}
