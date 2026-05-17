<?php

declare(strict_types=1);

namespace Modules\UserModule\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Modules\UserModule\Contracts\AuthServiceInterface;

/**
 * Auth Service
 *
 * Handles user authentication logic including registration, login, logout, and token-based user retrieval.
 */
class AuthService implements AuthServiceInterface
{
    /**
     * Register a new user
     *
     * Creates a user in the database, hashes the password, and generates a random 80-character hex API token.
     *
     * @param  array  $data  User registration data. Example: ["name" => "Jane", "email" => "jane@example.com", "password" => "password"]
     * @return array The registered user details and token. Example: ["user" => ["id" => "...", "name" => "..."], "token" => "abc..."]
     *
     * @throws \InvalidArgumentException If the email is already taken. Example: throw new \InvalidArgumentException("Email exists")
     */
    public function register(array $data): array
    {
        $existing = User::where('email', $data['email'])->first();
        if ($existing !== null) {
            throw new \InvalidArgumentException('A user with this email already exists.');
        }

        $token = bin2hex(random_bytes(40));

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'api_token' => $token,
        ]);

        return [
            'user' => $user->only(['id', 'name', 'email']),
            'token' => $token,
        ];
    }

    /**
     * Authenticate a user
     *
     * Verifies email and password, and generates a new API token upon successful login.
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
            throw new \InvalidArgumentException('Invalid email or password.');
        }

        $token = bin2hex(random_bytes(40));
        $user->update(['api_token' => $token]);

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
     * @param  string  $token  The API token to clear. Example: "80-char-hex-token"
     */
    public function logout(string $token): void
    {
        $user = User::where('api_token', $token)->first();
        if ($user !== null) {
            $user->update(['api_token' => null]);
        }
    }

    /**
     * Get user by token
     *
     * Finds a user record by its API token.
     *
     * @param  string  $token  The API token to search for. Example: "80-char-hex-token"
     * @return array|null User details or null if not found. Example: ["id" => "...", "name" => "..."]
     */
    public function getUserByToken(string $token): ?array
    {
        $user = User::where('api_token', $token)->first();

        return $user?->only(['id', 'name', 'email']);
    }
}
