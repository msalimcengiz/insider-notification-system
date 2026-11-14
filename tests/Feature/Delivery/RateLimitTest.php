<?php

declare(strict_types=1);

use App\Enums\Channel;
use App\Jobs\Middleware\ThrottleChannel;
use App\Jobs\SendNotification;
use App\Services\RateLimiting\SlidingWindowRateLimiter;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    $this->flushRedis();
    $this->limiter = app(SlidingWindowRateLimiter::class);
});

it('allows up to the limit within a window', function (): void {
    $results = array_map(fn (): int => $this->limiter->attempt('test', 100), range(1, 100));

    expect(array_unique($results))->toBe([0]);
});

it('rejects the call over the limit and says how long to wait', function (): void {
    foreach (range(1, 100) as $_) {
        $this->limiter->attempt('test', 100);
    }

    $wait = $this->limiter->attempt('test', 100);

    expect($wait)->toBeGreaterThan(0)->toBeLessThanOrEqual(1001);
});

it('keeps separate windows per key', function (): void {
    foreach (range(1, 3) as $_) {
        $this->limiter->attempt('channel:sms', 3);
    }

    expect($this->limiter->attempt('channel:sms', 3))->toBeGreaterThan(0)
        ->and($this->limiter->attempt('channel:email', 3))->toBe(0);
});

it('frees slots as the window slides', function (): void {
    foreach (range(1, 2) as $_) {
        $this->limiter->attempt('slide', 2, windowMs: 200);
    }
    expect($this->limiter->attempt('slide', 2, windowMs: 200))->toBeGreaterThan(0);

    usleep(220_000);

    expect($this->limiter->attempt('slide', 2, windowMs: 200))->toBe(0);
});

it('lets the job through when a slot is free', function (): void {
    $middleware = new ThrottleChannel($this->limiter, perSecond: 5, maxWaitMs: 2000);
    $job = (new SendNotification('id', Channel::Sms))->withFakeQueueInteractions();
    $ran = 0;

    foreach (range(1, 5) as $_) {
        $middleware->handle($job, function () use (&$ran): void {
            $ran++;
        });
    }

    expect($ran)->toBe(5);
    $job->assertNotReleased();
});

it('waits for the next free slot instead of releasing the job', function (): void {
    $middleware = new ThrottleChannel($this->limiter, perSecond: 2, maxWaitMs: 2000);
    $job = (new SendNotification('id', Channel::Push))->withFakeQueueInteractions();
    $ran = 0;
    $next = function () use (&$ran): void {
        $ran++;
    };

    $startedAt = microtime(true);
    foreach (range(1, 3) as $_) {
        $middleware->handle($job, $next);
    }
    $elapsed = microtime(true) - $startedAt;

    expect($ran)->toBe(3)->and($elapsed)->toBeGreaterThan(0.5);
    $job->assertNotReleased();
});

it('releases the job when it would have to wait too long', function (): void {
    Sleep::fake();
    $middleware = new ThrottleChannel($this->limiter, perSecond: 1, maxWaitMs: 100);
    $job = (new SendNotification('id', Channel::Email))->withFakeQueueInteractions();
    $ran = 0;

    foreach (range(1, 2) as $_) {
        $middleware->handle($job, function () use (&$ran): void {
            $ran++;
        });
    }

    expect($ran)->toBe(1);
    $job->assertReleased(1);
});
