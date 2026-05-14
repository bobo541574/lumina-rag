<?php

declare(strict_types=1);

namespace Modules\ChatModule\Commands;

use Illuminate\Console\Command;
use Modules\ChatModule\Models\ChatSession;

class CleanupExpiredSessions extends Command
{
    protected $signature = 'chat:cleanup
        {--dry-run : Show expired sessions without deleting them}';

    protected $description = 'Soft-delete chat sessions inactive for over 30 days';

    public function handle(): int
    {
        $cutoff = now()->subDays(30);
        $query = ChatSession::whereNull('deleted_at')
            ->where('last_activity_at', '<', $cutoff);

        $count = $query->count();

        if ($count === 0) {
            $this->info('No expired sessions found.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Found {$count} expired session(s) that would be deleted.");

            return self::SUCCESS;
        }

        $query->each(function (ChatSession $session): void {
            $session->messages()->delete();
            $session->delete();
        });

        $this->info("Cleaned up {$count} expired session(s).");

        return self::SUCCESS;
    }
}
