<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Enums\NotificationStatus;
use App\Jobs\SendNotification;
use App\Models\Notification;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Drops jobs whose notification can no longer be claimed (cancelled, sent,
 * failed, or a duplicate job from the recovery sweeper) before they reach the
 * circuit breaker and the rate limiter. Without this check such a job would
 * still take a rate limit slot and lower real throughput, which matters most
 * when the sweeper re-dispatches a large backlog.
 *
 * It is only a cheap pre-check: the atomic claim in DeliverNotification stays
 * the real guard against two workers sending the same notification.
 */
final class SkipUnclaimable
{
    public function handle(SendNotification $job, Closure $next): mixed
    {
        $status = Notification::query()->whereKey($job->notificationId)->value('status');
        $status = $status instanceof NotificationStatus ? $status : NotificationStatus::tryFrom((string) $status);

        if (! in_array($status, [NotificationStatus::Queued, NotificationStatus::Retrying], true)) {
            Log::info('notification.job_skipped', [
                'notification_id' => $job->notificationId,
                'status' => $status?->value,
            ]);

            return null;
        }

        return $next($job);
    }
}
