<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Services\Delivery\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Queues scheduled notifications whose time has come.
 *
 * Scheduled notifications wait in PostgreSQL, not as delayed jobs in Redis:
 * they can be weeks away, they stay cancellable with a plain status update,
 * and nothing is lost if Redis is flushed. Runs every 10 seconds, so the
 * delivery delay is at most ~10s plus queue time.
 */
final class DispatchScheduledNotifications extends Command
{
    protected $signature = 'notifications:dispatch-scheduled {--limit=10000 : Maximum notifications per run}';

    protected $description = 'Queue scheduled notifications that are due';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $limit = (int) $this->option('limit');
        $dispatched = 0;

        // Partial index notifications_due_scheduled_index covers this query.
        Notification::query()
            ->where('status', NotificationStatus::Scheduled)
            ->where('scheduled_at', '<=', now())
            ->limit($limit)
            ->select('id')
            ->chunkById(1000, function ($chunk) use ($dispatcher, &$dispatched): void {
                $dispatched += $dispatcher->dispatch(
                    $chunk->pluck('id')->all(),
                    from: [NotificationStatus::Scheduled],
                );
            });

        if ($dispatched > 0) {
            Log::info('notifications.scheduled_dispatched', ['count' => $dispatched]);
        }

        $this->info("Dispatched {$dispatched} scheduled notification(s).");

        return self::SUCCESS;
    }
}
