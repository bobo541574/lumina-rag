<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Providers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\ServiceProvider;
use Modules\SettingsModule\Contracts\AiModelServiceInterface;
use Modules\SettingsModule\Contracts\TermAliasServiceInterface;
use Modules\SettingsModule\Services\AiModelService;
use Modules\SettingsModule\Services\TermAliasService;

/**
 * Settings Module Service Provider
 *
 * Registers and bootstraps the SettingsModule within the Laravel application.
 * Binds service interfaces to their concrete implementations as singletons
 * and loads the module's migrations and routes when the module is enabled.
 *
 * The TermAliasService receives a CacheRepository dependency via a closure
 * factory, while the AiModelService is resolved directly by the container.
 * Module enablement is checked against config/modules.php at boot time.
 */
class SettingsModuleServiceProvider extends ServiceProvider
{
    /**
     * Register module services in the container
     *
     * Binds AiModelServiceInterface to AiModelService as a singleton, and
     * TermAliasServiceInterface to a factory closure that injects the
     * CacheRepository. Both bindings are lazy-resolved on first use.
     */
    public function register(): void
    {
        $this->app->singleton(AiModelServiceInterface::class, AiModelService::class);
        $this->app->singleton(TermAliasServiceInterface::class, fn ($app): TermAliasService => new TermAliasService(
            $app->make(CacheRepository::class),
        ));
    }

    /**
     * Boot module migrations and routes
     *
     * Checks the modules.settings.enabled config flag. If enabled, loads
     * the module's database migrations and route definitions. The config
     * defaults to true so the module is active unless explicitly disabled.
     */
    public function boot(): void
    {
        if (! config('modules.modules.settings.enabled', true)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/settings.php');
    }
}
