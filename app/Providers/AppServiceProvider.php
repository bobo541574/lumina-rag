<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    /** @var array<string> Artisan commands that drop tables queued jobs rely on */
    private const SCHEMA_RESET_COMMANDS = ['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback', 'db:wipe'];

    public function boot(): void
    {
        $this->pauseQueueDuringSchemaResets();
    }

    /**
     * Pause Horizon while tables are dropped and recreated.
     *
     * Otherwise a running worker picks up jobs mid-migration and fails with
     * "relation ... does not exist". A no-op when Horizon is not running.
     */
    private function pauseQueueDuringSchemaResets(): void
    {
        if ($this->app->runningUnitTests() || ! class_exists(Horizon::class)) {
            return;
        }

        $horizon = function (string $command): void {
            try {
                Artisan::call($command);
            } catch (\Throwable) {
                // Redis/Horizon unavailable: nothing to pause.
            }
        };

        Event::listen(CommandStarting::class, function (CommandStarting $event) use ($horizon): void {
            if (in_array($event->command, self::SCHEMA_RESET_COMMANDS, true)) {
                $horizon('horizon:pause');
            }
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event) use ($horizon): void {
            if (in_array($event->command, self::SCHEMA_RESET_COMMANDS, true)) {
                $horizon('horizon:continue');
            }
        });
    }
}
