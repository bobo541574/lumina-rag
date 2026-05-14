<?php

declare(strict_types=1);

namespace Modules\UserModule\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\UserModule\Contracts\AuthServiceInterface;
use Modules\UserModule\Services\AuthService;

class UserModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuthServiceInterface::class, AuthService::class);
    }

    public function boot(): void
    {
        if (! config('modules.modules.user.enabled', true)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/auth.php');
    }
}
