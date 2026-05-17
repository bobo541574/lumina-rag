<?php

declare(strict_types=1);

namespace Modules\ChatModule\Commands;

use Illuminate\Console\Command;
use Modules\ChatModule\Models\ChatSession;

/**
 * Cleanup Expired Sessions Command
 *
 * Artisan command that soft-deletes chat sessions which have been inactive
 * for more than 30 days. Also soft-deletes all associated messages.
 * Supports a --dry-run flag to preview which sessions would be affected
 * without actually deleting them. Intended to be run as a scheduled task.
 */
class CleanupExpiredSessions extends Command
{
    protected $signature = 'chat:cleanup
        {--dry-run : Show expired sessions without deleting them}';

    protected $description = 'Soft-delete chat sessions inactive for over 30 days';

    /**
     * Execute the console command
     *
     * Queries all non-deleted sessions with last_activity_at older than 30 days.
     * If --dry-run is set, reports the count without deleting.
     * Otherwise, soft-deletes each session and its messages, then reports the count.
     *
     * @return int Exit code (0 for success, 1 for failure)
     *
     * @example php artisan chat:cleanup → "Cleaned up 5 expired session(s)."
     * @example php artisan chat:cleanup --dry-run → "Found 5 expired session(s) that would be deleted."
     */
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
