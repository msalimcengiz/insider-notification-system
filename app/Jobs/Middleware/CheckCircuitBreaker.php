<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Jobs\SendNotification;
use App\Services\CircuitBreaker\CircuitBreaker;
use Closure;

/**
 * Holds a channel's jobs back while its circuit is open. Runs before the rate
 * limiter so a job that is not going to call the provider does not take a
 * rate limit slot. Releases are spread a little so the backlog does not
 * return in one wave when the cool-down ends.
 */
final readonly class CheckCircuitBreaker
{
    public function __construct(private CircuitBreaker $breaker) {}

    public function handle(SendNotification $job, Closure $next): mixed
    {
        $wait = $this->breaker->allowRequest($job->channel);

        if ($wait > 0) {
            $job->release($wait + random_int(0, 3));

            return null;
        }

        return $next($job);
    }
}
