<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Jobs\SendNotification;
use App\Services\RateLimiting\SlidingWindowRateLimiter;
use Closure;
use Illuminate\Support\Sleep;
use OpenTelemetry\API\Trace\Span;

/**
 * Enforces the per-channel rate limit before a provider call.
 *
 * When the window is full the worker waits for the next free slot (which is
 * at most one window away) instead of releasing the job: a released job goes
 * to the back of the queue, so releasing would shuffle the queue order and
 * let low priority work overtake high priority work under load. Only a wait
 * longer than `max_wait_ms` (heavy contention) falls back to a release.
 */
final readonly class ThrottleChannel
{
    public function __construct(
        private SlidingWindowRateLimiter $limiter,
        private int $perSecond,
        private int $maxWaitMs,
        private int $windowMs = 1000,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            app(SlidingWindowRateLimiter::class),
            (int) config('notifications.rate_limit.per_second'),
            (int) config('notifications.rate_limit.max_wait_ms'),
            (int) config('notifications.rate_limit.window_ms', 1000),
        );
    }

    public function handle(SendNotification $job, Closure $next): mixed
    {
        $waited = 0;

        while (($waitMs = $this->limiter->attempt("channel:{$job->channel->value}", $this->perSecond, $this->windowMs)) > 0) {
            if ($waited + $waitMs > $this->maxWaitMs) {
                Span::getCurrent()->addEvent('rate_limit.released', ['rate_limit.waited_ms' => $waited]);
                $job->release(1);

                return null;
            }

            Sleep::usleep($waitMs * 1000);
            $waited += $waitMs;
        }

        if ($waited > 0) {
            // Shows up on the job's trace as time spent waiting for a slot.
            Span::getCurrent()->addEvent('rate_limit.waited', ['rate_limit.waited_ms' => $waited]);
        }

        return $next($job);
    }
}
