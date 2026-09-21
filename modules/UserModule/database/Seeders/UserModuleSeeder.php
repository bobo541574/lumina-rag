<?php

declare(strict_types=1);

namespace Modules\UserModule\Database\Seeders;

use App\Models\User;
use App\Support\ApiToken;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserModuleSeeder extends Seeder
{
    public function run(): void
    {
        $seedUsers = function (): array {
            $admin = [
                'name' => 'Admin User',
                'email' => 'admin@lumina.test',
                'password' => Hash::make('password123'),
                'is_admin' => true,
            ];

            $members = [
                ['name' => 'Sarah Chen', 'email' => 'sarah.chen@acmecorp.com', 'password' => Hash::make('securePass1')],
                ['name' => 'Marcus Johnson', 'email' => 'marcus.j@startup.io', 'password' => Hash::make('welcome2026')],
                ['name' => 'Elena Rodriguez', 'email' => 'elena.r@datawise.ai', 'password' => Hash::make('Password!23')],
                ['name' => 'James Okafor', 'email' => 'james.okafor@enterprise.co', 'password' => Hash::make('J0urn3y!')],
            ];

            return array_merge([$admin], $members);
        };

        foreach ($seedUsers() as $user) {
            $existing = User::where('email', $user['email'])->first();

            if ($existing !== null) {
                continue;
            }

            // Tokens are stored as SHA-256 digests; the raw value is never kept.
            User::create(array_merge($user, [
                'api_token' => ApiToken::hash(ApiToken::make()),
            ]));
        }

        // Re-run safety net: any token not already stored as a 64-char digest is
        // legacy plaintext — replace it with its digest (idempotent).
        User::query()
            ->whereNotNull('api_token')
            ->whereRaw('length(api_token) <> 64')
            ->each(function (User $user): void {
                $user->update(['api_token' => ApiToken::hash($user->api_token)]);
            });
    }
}
