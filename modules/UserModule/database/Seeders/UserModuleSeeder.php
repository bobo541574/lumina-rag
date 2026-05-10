<?php

declare(strict_types=1);

namespace Modules\UserModule\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserModuleSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name' => 'Admin User',
                'email' => 'admin@lumina.test',
                'password' => Hash::make('password123'),
                'api_token' => bin2hex(random_bytes(40)),
            ],
            [
                'name' => 'Sarah Chen',
                'email' => 'sarah.chen@acmecorp.com',
                'password' => Hash::make('securePass1'),
                'api_token' => bin2hex(random_bytes(40)),
            ],
            [
                'name' => 'Marcus Johnson',
                'email' => 'marcus.j@startup.io',
                'password' => Hash::make('welcome2026'),
                'api_token' => bin2hex(random_bytes(40)),
            ],
            [
                'name' => 'Elena Rodriguez',
                'email' => 'elena.r@datawise.ai',
                'password' => Hash::make('Password!23'),
                'api_token' => bin2hex(random_bytes(40)),
            ],
            [
                'name' => 'James Okafor',
                'email' => 'james.okafor@enterprise.co',
                'password' => Hash::make('J0urn3y!'),
                'api_token' => bin2hex(random_bytes(40)),
            ],
        ];

        foreach ($users as $user) {
            User::firstOrCreate(
                ['email' => $user['email']],
                $user,
            );
        }
    }
}
