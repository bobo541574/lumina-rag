<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\ChatModule\Database\Seeders\ChatModuleSeeder;
use Modules\DocumentModule\Database\Seeders\DocumentModuleSeeder;
use Modules\DocumentModule\Database\Seeders\ReportDemoSeeder;
use Modules\SettingsModule\Database\Seeders\SettingsModuleSeeder;
use Modules\UserModule\Database\Seeders\UserModuleSeeder;
use Modules\VectorStoreModule\Database\Seeders\VectorStoreModuleSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserModuleSeeder::class,
            ChatModuleSeeder::class,
            DocumentModuleSeeder::class,
            ReportDemoSeeder::class,
            VectorStoreModuleSeeder::class,
            SettingsModuleSeeder::class,
        ]);
    }
}
