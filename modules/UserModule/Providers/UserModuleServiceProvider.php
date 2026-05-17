<?php

declare(strict_types=1);

namespace Modules\UserModule\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\UserModule\Contracts\AuthServiceInterface;
use Modules\UserModule\Services\AuthService;

/**
 * User Module Service Provider
 *
 * Registers the User Module's core services into the Laravel container.
 * Binds the AuthServiceInterface to the concrete AuthService and loads
 * the module's database migrations and auth routes when the module is
 * enabled.
 *
 * Routes loaded: POST /api/auth/register, POST /api/auth/login,
 * POST /api/auth/logout, GET /api/auth/me.
 *
 * @throws \RuntimeException If the AuthService class cannot be resolved
 */
class UserModuleServiceProvider extends ServiceProvider
{
    /**
     * Register module services in the container.
     *
     * Binds AuthServiceInterface as a singleton to AuthService::class
     * for automatic dependency injection in controllers.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(AuthServiceInterface::class, AuthService::class);
    }

    /**
     * Boot module services.
     *
     * Checks if the module is enabled via config. When enabled, loads
     * the database migrations and auth routes.
     *
     * @return void
     */
    public function boot(): void
    {
        if (! config('modules.modules.user.enabled', true)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/auth.php');
    }
}
