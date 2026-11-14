<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\NotificationStatus;
use App\Events\NotificationStatusChanged;
use App\Exceptions\NotificationNotCancellable;
use App\Models\Notification;

/**
 * Cancels a notification that has not reached a worker yet.
 *
 * The status check and the update are a single conditional UPDATE, so a
 * worker claiming the notification at the same moment cannot interleave:
 * exactly one of them wins. Workers claim with the same kind of conditional
 * UPDATE and skip notifications that were cancelled in the meantime.
 */
final readonly class CancelNotification
{
    /**
     * @throws NotificationNotCancellable
     */
    public function handle(Notification $notification): Notification
    {
        $now = now();

        $cancelled = Notification::query()
            ->whereKey($notification->id)
            ->inStatus(NotificationStatus::cancellable())
            ->update([
                'status' => NotificationStatus::Cancelled,
                'cancelled_at' => $now,
                'next_attempt_at' => null,
                'updated_at' => $now,
            ]);

        $fresh = $notification->refresh();

        if ($cancelled > 0) {
            event(NotificationStatusChanged::from($fresh));
        }

        // Cancelling twice is not an error: the end state is what was asked for.
        if ($cancelled === 0 && $fresh->status !== NotificationStatus::Cancelled) {
            throw new NotificationNotCancellable($fresh->status);
        }

        return $fresh;
    }
}
