<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Modules\ChatModule\Database\Seeders\ChatModuleSeeder;
use Modules\DocumentModule\Database\Seeders\DocumentModuleSeeder;
use Modules\DocumentModule\Database\Seeders\ReportDemoSeeder;
use Modules\SettingsModule\Database\Seeders\SettingsModuleSeeder;
use Modules\UserModule\Database\Seeders\UserModuleSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // AI models first: document seeders record the active embedding model.
            SettingsModuleSeeder::class,
            UserModuleSeeder::class,
            ChatModuleSeeder::class,
            DocumentModuleSeeder::class,
            ReportDemoSeeder::class,
        ]);

        // Seeded chunks have no vectors yet. Queue real embeddings (newest
        // documents first, so recent data becomes searchable soonest); the
        // queue worker (Horizon) processes them in the background.
        $this->command?->info('Queueing embeddings for seeded documents (run Horizon to process them)...');
        Artisan::call('rag:reembed', ['--missing' => true], $this->command?->getOutput());
    }
}
