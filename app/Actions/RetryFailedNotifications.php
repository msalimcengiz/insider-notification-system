<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\NotificationStatus;
use App\Exceptions\NotificationNotRetryable;
use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Services\Delivery\NotificationDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Manual retry of failed notifications (the "dead letter" set).
 *
 * The notification keeps its attempt history; it gets a fresh retry budget
 * by moving retry_budget_start to the current attempt count.
 */
final readonly class RetryFailedNotifications
{
    public function __construct(private NotificationDispatcher $dispatcher) {}

    /**
     * @throws NotificationNotRetryable
     */
    public function one(Notification $notification): Notification
    {
        $status = $notification->refresh()->status;

        if ($status !== NotificationStatus::Failed) {
            throw new NotificationNotRetryable($status);
        }

        // Conditional on `failed`: a concurrent retry of the same
        // notification simply finds nothing left to do.
        $this->retry([$notification->id]);

        return $notification->refresh();
    }

    /**
     * @return int number of notifications re-queued
     */
    public function batch(NotificationBatch $batch): int
    {
        /** @var list<string> $ids */
        $ids = Notification::query()
            ->where('batch_id', $batch->id)
            ->where('status', NotificationStatus::Failed)
            ->pluck('id')
            ->all();

        return $this->retry($ids);
    }

    /**
     * @param  list<string>  $ids
     */
    private function retry(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $reset = 0;
        foreach (array_chunk($ids, 1000) as $chunk) {
            $reset += Notification::query()
                ->whereKey($chunk)
                ->where('status', NotificationStatus::Failed)
                ->update([
                    'retry_budget_start' => DB::raw('attempts'),
                    'failed_at' => null,
                    'updated_at' => now(),
                ]);
        }

        if ($reset === 0) {
            return 0;
        }

        return $this->dispatcher->dispatch($ids, from: [NotificationStatus::Failed]);
    }
}
