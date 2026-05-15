<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\SettingsModule\Services\AiModelService;

class SettingsModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiModelService::class);
    }

    public function boot(): void
    {
        if (! config('modules.modules.settings.enabled', true)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/settings.php');
    }
}
