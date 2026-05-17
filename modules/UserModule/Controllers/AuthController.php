<?php

declare(strict_types=1);

namespace Modules\UserModule\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\UserModule\Contracts\AuthServiceInterface;
use Modules\UserModule\Requests\LoginRequest;
use Modules\UserModule\Requests\RegisterRequest;

/**
 * Auth Controller
 *
 * Handles HTTP requests for user registration, login, logout, and profile
 * retrieval. Delegates all business logic to AuthServiceInterface.
 *
 * All responses use the standard envelope format: { success, message, data, errors }.
 * Errors from the service layer are caught and returned as JSON error responses
 * with appropriate HTTP status codes (201, 401, 409, 500).
 *
 * @param AuthServiceInterface $authService The authentication service implementation. Example: app(AuthServiceInterface::class)
 *
 * @throws \Throwable Caught and converted to JSON error responses in each method
 */
class AuthController extends Controller
{
    /** @var AuthServiceInterface The authentication service handling business logic */
    private AuthServiceInterface $authService;

    /**
     * @param  AuthServiceInterface  $authService  The authentication service. Example: app(AuthServiceInterface::class)
     */
    public function __construct(AuthServiceInterface $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Register a new user
     *
     * Validates registration data (name, email, password) via RegisterRequest,
     * delegates to AuthService::register, and returns the new user with an
     * API token. Returns 409 if the email is already taken.
     *
     * @param  RegisterRequest  $request  Validated registration request. Example: new RegisterRequest(["name" => "John", "email" => "john@test.com", "password" => "secret123"])
     * @return JsonResponse User + token on success (201), error on conflict. Example: response()->json(["success" => true, "data" => ["user" => [...], "token" => "..."]], 201)
     *
     * @throws \InvalidArgumentException Caught and returned as 409 JSON response
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->register($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'User registered successfully.',
                'data' => $result,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);
        }
    }

    /**
     * Authenticate a user
     *
     * Validates credentials via LoginRequest, delegates to AuthService::login,
     * and returns the authenticated user with an API token. Returns 401 on
     * invalid credentials.
     *
     * @param  LoginRequest  $request  Validated login request. Example: new LoginRequest(["email" => "john@test.com", "password" => "secret123"])
     * @return JsonResponse User + token on success. Example: response()->json(["success" => true, "data" => ["user" => [...], "token" => "..."]])
     *
     * @throws \InvalidArgumentException Caught and returned as 401 JSON response
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->login(
                $request->input('email'),
                $request->input('password'),
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 401);
        }
    }

    /**
     * Logout a user
     *
     * Invalidates the current bearer token via AuthService::logout.
     * Always returns a success response to the client, even if no
     * token was provided. Internal errors are caught and returned as 500.
     *
     * @param  Request  $request  The incoming HTTP request. Example: request()
     * @return JsonResponse Logout confirmation. Example: response()->json(["success" => true, "message" => "Logged out successfully."])
     *
     * @throws \Throwable Caught and returned as 500 JSON response
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $token = $request->bearerToken();

            if ($token !== null) {
                $this->authService->logout($token);
            }

            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Logout failed.',
            ], 500);
        }
    }

    /**
     * Get authenticated user profile
     *
     * Retrieves the user record associated with the request's bearer token.
     * Returns 401 if no valid token is provided or the token is not found.
     *
     * @param  Request  $request  The incoming HTTP request. Example: request()
     * @return JsonResponse User data on success, 401 if unauthenticated. Example: response()->json(["success" => true, "data" => ["id" => "01J...", "name" => "John", "email" => "john@test.com"]])
     *
     * @throws \Throwable Caught and returned as 500 JSON response
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $token = $request->bearerToken();
            $user = $token !== null ? $this->authService->getUserByToken($token) : null;

            if ($user === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            return response()->json([
                'success' => true,
                'data' => $user,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred.',
            ], 500);
        }
    }
}
