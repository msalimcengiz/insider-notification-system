<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a notification.
 *
 *   Happy path:   [scheduled →] pending → queued → processing → sent
 *   Retry:        processing → retrying → processing → …
 *   Give up:      processing → failed           (permanent error / budget exhausted)
 *   Manual retry: failed → queued
 *   Recovery:     processing | retrying → queued (stuck after a worker crash)
 *   Cancel:       scheduled | pending | queued | retrying → cancelled
 */
enum NotificationStatus: string
{
    /** Waiting for its scheduled_at time. */
    case Scheduled = 'scheduled';

    /** Persisted, not yet handed to the queue. */
    case Pending = 'pending';

    /** Sitting in a queue, waiting for a worker. */
    case Queued = 'queued';

    /** Claimed by a worker; a provider call is in flight. */
    case Processing = 'processing';

    /** Last attempt failed with a retryable error; waiting for its backoff. */
    case Retrying = 'retrying';

    /** Accepted by the provider. */
    case Sent = 'sent';

    /** Permanent error, or retry budget exhausted. */
    case Failed = 'failed';

    /** Cancelled by an API consumer before it was sent. */
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Scheduled => [self::Pending, self::Queued, self::Cancelled],
            self::Pending => [self::Queued, self::Cancelled],
            self::Queued => [self::Processing, self::Cancelled],
            self::Processing => [self::Sent, self::Retrying, self::Failed, self::Queued],
            self::Retrying => [self::Processing, self::Queued, self::Cancelled],
            self::Failed => [self::Queued],
            self::Sent, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * Statuses from which a notification can still be cancelled, i.e. it has
     * not reached a worker yet (or is idle between retry attempts).
     *
     * @return list<self>
     */
    public static function cancellable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $status): bool => $status->canTransitionTo(self::Cancelled),
        ));
    }

    public function isCancellable(): bool
    {
        return $this->canTransitionTo(self::Cancelled);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Sent, self::Failed, self::Cancelled], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
