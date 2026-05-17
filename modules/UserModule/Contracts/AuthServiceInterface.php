<?php

declare(strict_types=1);

namespace Modules\UserModule\Contracts;

use Illuminate\Validation\ValidationException;

/**
 * Auth Service Interface
 *
 * Defines the contract for authentication services, including registration, login, logout, and user retrieval.
 */
interface AuthServiceInterface
{
    /**
     * Register a new user
     *
     * Creates a new user record and generates an initial API token.
     *
     * @param  array  $data  User registration data (name, email, password). Example: ["name" => "John", "email" => "john@example.com", "password" => "secret123"]
     * @return array The registered user and their access token. Example: ["user" => $user, "token" => "abc..."]
     *
     * @throws \Exception If registration fails (e.g., duplicate email). Example: throw new \Exception("Email already exists")
     */
    public function register(array $data): array;

    /**
     * Authenticate a user
     *
     * Validates credentials and returns a user and access token.
     *
     * @param  string  $email  User's email address. Example: "test@example.com"
     * @param  string  $password  User's plain-text password. Example: "password123"
     * @return array The authenticated user and their access token. Example: ["user" => $user, "token" => "abc..."]
     *
     * @throws ValidationException If authentication fails. Example: throw ValidationException::withMessages(["email" => "Invalid credentials"])
     */
    public function login(string $email, string $password): array;

    /**
     * Logout a user
     *
     * Invalidates the provided API token.
     *
     * @param  string  $token  The API token to invalidate. Example: "80-char-hex-token"
     */
    public function logout(string $token): void;

    /**
     * Get user by token
     *
     * Retrieves the user associated with the given API token.
     *
     * @param  string  $token  The API token to lookup. Example: "80-char-hex-token"
     * @return array|null The user data or null if not found. Example: ["id" => "...", "name" => "...", "email" => "..."]
     */
    public function getUserByToken(string $token): ?array;
}
