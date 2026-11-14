<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\DeliverNotification;
use App\Enums\Channel;
use App\Jobs\Middleware\CheckCircuitBreaker;
use App\Jobs\Middleware\SkipUnclaimable;
use App\Jobs\Middleware\ThrottleChannel;
use App\Jobs\Middleware\TraceJob;
use App\Support\Tracing\Tracing;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers one notification.
 *
 * Retries of *delivery* failures are decided by the RetryPolicy and counted
 * in `notifications.attempts`, not by the queue: the queue's own attempt
 * counter also grows on every release (e.g. rate limiting), so `tries` is
 * unlimited and the job is bounded by `retryUntil()` instead. Unexpected
 * exceptions (database down, bugs) are capped separately by `maxExceptions`.
 */
final class SendNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 30;

    /**
     * W3C trace context of the span that dispatched the job, so the worker's
     * span continues the same trace (see TraceJob).
     *
     * @var array<string, string>
     */
    public array $traceContext = [];

    public function __construct(
        public readonly string $notificationId,
        public readonly Channel $channel,
    ) {
        $this->traceContext = app(Tracing::class)->currentCarrier();
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            app(TraceJob::class),
            new SkipUnclaimable,
            app(CheckCircuitBreaker::class),
            ThrottleChannel::fromConfig(),
        ];
    }

    public function handle(DeliverNotification $deliver): void
    {
        $retryIn = $deliver->handle($this->notificationId);

        if ($retryIn !== null) {
            $this->release($retryIn);
        }
    }

    public function retryUntil(): CarbonImmutable
    {
        return now()->addDay();
    }

    /**
     * Backoff (seconds) between unexpected exceptions.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ["notification:{$this->notificationId}", "channel:{$this->channel->value}"];
    }
}
