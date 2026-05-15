<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\SettingsModule\Services\SettingsService;

class SettingsModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
    }

    public function boot(): void
    {
        if (! config('modules.modules.settings.enabled', true)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/settings.php');

        $this->app->booted(function (): void {
            try {
                $this->app->make(SettingsService::class)->loadIntoConfig();
            } catch (\Throwable) {
            }
        });
    }
}
