<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use Closure;

/**
 * Exponential backoff with jitter.
 *
 * Jitter spreads retries of notifications that failed together (e.g. during
 * a provider outage) so they do not hit the provider again in lockstep.
 */
final readonly class RetryPolicy
{
    /** @var Closure(): float random number in [0, 1] */
    private Closure $random;

    /**
     * @param  (Closure(): float)|null  $random
     */
    public function __construct(
        public int $maxAttempts,
        private int $baseDelay,
        private float $multiplier,
        private int $maxDelay,
        private float $jitter,
        ?Closure $random = null,
    ) {
        $this->random = $random ?? fn (): float => mt_rand() / mt_getrandmax();
    }

    public static function fromConfig(): self
    {
        $config = (array) config('notifications.retry');

        return new self(
            maxAttempts: (int) ($config['max_attempts'] ?? 5),
            baseDelay: (int) ($config['base_delay'] ?? 10),
            multiplier: (float) ($config['multiplier'] ?? 3),
            maxDelay: (int) ($config['max_delay'] ?? 900),
            jitter: (float) ($config['jitter'] ?? 0.2),
        );
    }

    /**
     * Whether another attempt is allowed after `$attemptsMade` failed attempts.
     */
    public function shouldRetry(int $attemptsMade): bool
    {
        return $attemptsMade < $this->maxAttempts;
    }

    /**
     * Seconds to wait before the next attempt, after `$attemptsMade` attempts.
     */
    public function delayFor(int $attemptsMade, ?int $retryAfter = null): int
    {
        $exponential = $this->baseDelay * ($this->multiplier ** max(0, $attemptsMade - 1));
        $capped = min((float) $this->maxDelay, $exponential);

        // Uniformly spread in [capped * (1 - jitter), capped * (1 + jitter)].
        $spread = $capped * $this->jitter * (2 * ($this->random)() - 1);
        $delay = (int) round(min((float) $this->maxDelay, $capped + $spread));

        return max(1, $delay, $retryAfter ?? 0);
    }
}
