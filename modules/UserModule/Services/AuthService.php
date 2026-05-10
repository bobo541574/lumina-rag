<?php

declare(strict_types=1);

namespace Modules\UserModule\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Modules\UserModule\Contracts\AuthServiceInterface;

class AuthService implements AuthServiceInterface
{
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

    public function logout(string $token): void
    {
        $user = User::where('api_token', $token)->first();
        if ($user !== null) {
            $user->update(['api_token' => null]);
        }
    }

    public function getUserByToken(string $token): ?array
    {
        $user = User::where('api_token', $token)->first();

        return $user?->only(['id', 'name', 'email']);
    }
}
