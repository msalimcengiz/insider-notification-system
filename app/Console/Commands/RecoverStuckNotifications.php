<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Services\Delivery\NotificationDispatcher;
use App\Support\Tracing\Tracing;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Re-dispatches notifications that should be moving but are not.
 *
 * Redis is only the transport; PostgreSQL is the source of truth. A worker
 * can die between claiming and recording, a process can die between marking
 * a notification queued and pushing its job, and Redis can lose jobs. This
 * sweeper finds those notifications by status and age and queues them again.
 *
 * Re-queuing something that is in fact still in a queue only produces a
 * duplicate job, which is harmless: jobs proceed only if they can claim their
 * notification. A notification recovered from `processing` may already have
 * been accepted by the provider; the Idempotency-Key header (notification ID)
 * lets the provider drop the duplicate.
 */
final class RecoverStuckNotifications extends Command
{
    protected $signature = 'notifications:recover';

    protected $description = 'Re-dispatch notifications stuck in a non-final status';

    public function handle(NotificationDispatcher $dispatcher, Tracing $tracing): int
    {
        $config = (array) config('notifications.recovery');
        $limit = (int) ($config['max_per_run'] ?? 5000);

        $rules = [
            'pending' => [
                NotificationStatus::Pending,
                fn (Builder $q) => $q->where('updated_at', '<', now()->subSeconds((int) ($config['pending_after_seconds'] ?? 60))),
            ],
            'queued' => [
                NotificationStatus::Queued,
                fn (Builder $q) => $q->where('queued_at', '<', now()->subSeconds((int) ($config['queued_after_seconds'] ?? 900))),
            ],
            'processing' => [
                NotificationStatus::Processing,
                fn (Builder $q) => $q->where('processing_started_at', '<', now()->subSeconds((int) ($config['processing_after_seconds'] ?? 300))),
            ],
            'retrying' => [
                NotificationStatus::Retrying,
                fn (Builder $q) => $q->where('next_attempt_at', '<', now()->subSeconds((int) ($config['retrying_overdue_seconds'] ?? 300))),
            ],
        ];

        $stuck = [];
        foreach ($rules as $name => [$status, $isStuck]) {
            /** @var list<string> $ids */
            $ids = Notification::query()
                ->where('status', $status)
                ->where($isStuck)
                ->orderBy('updated_at')
                ->limit($limit)
                ->pluck('id')
                ->all();

            $stuck[$name] = [$status, $ids];
        }

        $recovered = array_map(fn (array $entry): int => 0, $stuck);

        // Runs every minute: only start a trace when something is stuck.
        if (array_filter($stuck, fn (array $entry): bool => $entry[1] !== []) !== []) {
            $tracing->inSpan('notifications:recover', function () use ($stuck, $dispatcher, &$recovered): void {
                foreach ($stuck as $name => [$status, $ids]) {
                    $recovered[$name] = $ids === [] ? 0 : $dispatcher->dispatch($ids, from: [$status]);
                }
            });
        }

        $total = array_sum($recovered);

        if ($total > 0) {
            Log::warning('notifications.recovered', $recovered + ['total' => $total]);
        }

        $this->info("Recovered {$total} notification(s): ".json_encode($recovered));

        return self::SUCCESS;
    }
}
