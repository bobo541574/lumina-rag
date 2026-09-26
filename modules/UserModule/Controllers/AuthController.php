<?php

declare(strict_types=1);

namespace Modules\UserModule\Controllers;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\UserModule\Contracts\AuthServiceInterface;
use Modules\UserModule\Requests\LoginRequest;
use Modules\UserModule\Requests\RegisterRequest;
use Modules\UserModule\Services\AccountDeletionService;

/**
 * Auth Controller
 *
 * Handles HTTP requests for user registration, login, logout, profile
 * retrieval, and account erasure. Delegates all business logic to
 * AuthServiceInterface (and AccountDeletionService for deletion).
 *
 * All responses use the standard envelope format: { success, message, data, errors }.
 * Errors from the service layer are caught and returned as JSON error responses
 * with appropriate HTTP status codes (201, 401, 409, 500).
 *
 * @param  AuthServiceInterface  $authService  The authentication service implementation. Example: app(AuthServiceInterface::class)
 */
class AuthController extends Controller
{
    /** @var AuthServiceInterface The authentication service handling business logic */
    private AuthServiceInterface $authService;

    private AccountDeletionService $deletionService;

    /**
     * @param  AuthServiceInterface  $authService  The authentication service. Example: app(AuthServiceInterface::class)
     * @param  AccountDeletionService  $deletionService  The account erasure service. Example: app(AccountDeletionService::class)
     */
    public function __construct(AuthServiceInterface $authService, AccountDeletionService $deletionService)
    {
        $this->authService = $authService;
        $this->deletionService = $deletionService;
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
            return ApiResponse::success(
                $this->authService->register($request->validated()),
                'User registered successfully.',
                201,
            );
        } catch (\InvalidArgumentException $e) {
            Log::channel('security')->warning('user.register_conflict', [
                'email' => $request->input('email'),
            ]);

            return ApiResponse::error($e->getMessage(), 409);
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
            return ApiResponse::success($this->authService->login(
                $request->input('email'),
                $request->input('password'),
            ));
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 401);
        }
    }

    /**
     * Logout a user
     *
     * Invalidates the authenticated user's token via AuthService::logout.
     * The auth.token middleware has already rejected missing/invalid tokens.
     *
     * @param  Request  $request  The incoming HTTP request. Example: request()
     * @return JsonResponse Logout confirmation. Example: response()->json(["success" => true, "message" => "Logged out successfully."])
     */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->input('authenticated_user'));

        return ApiResponse::success(message: 'Logged out successfully.');
    }

    /**
     * Get authenticated user profile
     *
     * Returns the user resolved by the auth.token middleware (which answers
     * 401 for missing, unknown or expired tokens).
     *
     * @param  Request  $request  The incoming HTTP request. Example: request()
     * @return JsonResponse User data. Example: response()->json(["success" => true, "data" => ["id" => "01J...", "name" => "John", "email" => "john@test.com"]])
     */
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $request->input('authenticated_user')->only(['id', 'name', 'email']),
        );
    }

    /**
     * Delete the authenticated account and all associated personal data
     *
     * Erases documents, chunks, vectors, chat history, and semantic-cache
     * entries owned by the user, then removes the user record. Satisfies
     * the right to erasure (ISO 27701:8.2.4).
     *
     * @param  Request  $request  The incoming request with authenticated_user. Example: request()
     * @return JsonResponse Confirmation on success, 500 on failure. Example: response()->json(["success" => true, "message" => "Account deleted."])
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->input('authenticated_user');

        try {
            $this->deletionService->delete($user);

            return ApiResponse::success(message: 'Account deleted.');
        } catch (\Throwable $e) {
            Log::channel('security')->error('user.delete_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return ApiResponse::error('Unable to delete the account.', 500);
        }
    }
}
