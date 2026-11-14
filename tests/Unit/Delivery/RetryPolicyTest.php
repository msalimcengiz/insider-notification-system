<?php

declare(strict_types=1);

use App\Services\Delivery\RetryPolicy;

function retryPolicy(float $random = 0.5, float $jitter = 0.2): RetryPolicy
{
    return new RetryPolicy(
        maxAttempts: 5,
        baseDelay: 10,
        multiplier: 3,
        maxDelay: 900,
        jitter: $jitter,
        random: fn (): float => $random,
    );
}

it('grows the delay exponentially', function (): void {
    // random = 0.5 → no jitter offset
    $policy = retryPolicy();

    expect(array_map(fn (int $n): int => $policy->delayFor($n), [1, 2, 3, 4]))
        ->toBe([10, 30, 90, 270]);
});

it('caps the delay', function (): void {
    expect(retryPolicy()->delayFor(10))->toBe(900);
});

it('spreads delays by the jitter factor', function (): void {
    expect(retryPolicy(random: 0.0)->delayFor(3))->toBe(72)   // 90 - 20%
        ->and(retryPolicy(random: 1.0)->delayFor(3))->toBe(108); // 90 + 20%
});

it('never exceeds the cap with jitter', function (): void {
    expect(retryPolicy(random: 1.0)->delayFor(10))->toBe(900);
});

it('honours a longer Retry-After', function (): void {
    expect(retryPolicy()->delayFor(1, retryAfter: 60))->toBe(60)
        ->and(retryPolicy()->delayFor(3, retryAfter: 5))->toBe(90);
});

it('stops after the maximum number of attempts', function (): void {
    expect(retryPolicy()->shouldRetry(4))->toBeTrue()
        ->and(retryPolicy()->shouldRetry(5))->toBeFalse();
});
