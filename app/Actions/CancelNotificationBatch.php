<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Models\NotificationBatch;

/**
 * Cancels every notification of a batch that is still cancellable, in one
 * statement. Notifications already being processed or finished are left
 * untouched.
 */
final readonly class CancelNotificationBatch
{
    /**
     * @return int number of notifications cancelled
     */
    public function handle(NotificationBatch $batch): int
    {
        $now = now();

        return Notification::query()
            ->where('batch_id', $batch->id)
            ->inStatus(NotificationStatus::cancellable())
            ->update([
                'status' => NotificationStatus::Cancelled,
                'cancelled_at' => $now,
                'next_attempt_at' => null,
                'updated_at' => $now,
            ]);
    }
}
