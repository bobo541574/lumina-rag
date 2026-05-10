<?php

declare(strict_types=1);

namespace Modules\UserModule\Contracts;

interface AuthServiceInterface
{
    public function register(array $data): array;

    public function login(string $email, string $password): array;

    public function logout(string $token): void;

    public function getUserByToken(string $token): ?array;
}
