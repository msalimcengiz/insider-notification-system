<?php

declare(strict_types=1);

use App\Actions\DeliverNotification;
use App\Enums\Channel;
use App\Enums\NotificationStatus;
use App\Jobs\Middleware\CheckCircuitBreaker;
use App\Jobs\SendNotification;
use App\Models\Notification;
use App\Services\CircuitBreaker\CircuitBreaker;
use App\Services\CircuitBreaker\CircuitState;
use App\Services\Delivery\RetryPolicy;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->flushRedis();
    $this->breaker = new CircuitBreaker(app(Redis::class), failureThreshold: 3, cooldownSeconds: 1, failureWindowSeconds: 60);
    $this->app->instance(CircuitBreaker::class, $this->breaker);
});

it('stays closed below the failure threshold', function (): void {
    $this->breaker->recordFailure(Channel::Sms);
    $this->breaker->recordFailure(Channel::Sms);

    expect($this->breaker->state(Channel::Sms))->toBe(CircuitState::Closed)
        ->and($this->breaker->allowRequest(Channel::Sms))->toBe(0);
});

it('opens after consecutive failures and blocks calls', function (): void {
    foreach (range(1, 3) as $_) {
        $this->breaker->recordFailure(Channel::Sms);
    }

    expect($this->breaker->state(Channel::Sms))->toBe(CircuitState::Open)
        ->and($this->breaker->allowRequest(Channel::Sms))->toBeGreaterThan(0);
});

it('resets the failure count on success', function (): void {
    $this->breaker->recordFailure(Channel::Sms);
    $this->breaker->recordFailure(Channel::Sms);
    $this->breaker->recordSuccess(Channel::Sms);
    $this->breaker->recordFailure(Channel::Sms);

    expect($this->breaker->state(Channel::Sms))->toBe(CircuitState::Closed);
});

it('isolates channels', function (): void {
    foreach (range(1, 3) as $_) {
        $this->breaker->recordFailure(Channel::Sms);
    }

    expect($this->breaker->state(Channel::Email))->toBe(CircuitState::Closed)
        ->and($this->breaker->allowRequest(Channel::Email))->toBe(0);
});

it('lets a single probe through after the cool-down', function (): void {
    foreach (range(1, 3) as $_) {
        $this->breaker->recordFailure(Channel::Push);
    }
    usleep(1_100_000);

    expect($this->breaker->state(Channel::Push))->toBe(CircuitState::HalfOpen)
        ->and($this->breaker->allowRequest(Channel::Push))->toBe(0)        // the probe
        ->and($this->breaker->allowRequest(Channel::Push))->toBeGreaterThan(0); // everyone else waits
});

it('closes when the probe succeeds and re-opens when it fails', function (): void {
    foreach (range(1, 3) as $_) {
        $this->breaker->recordFailure(Channel::Push);
    }
    usleep(1_100_000);
    $this->breaker->allowRequest(Channel::Push);
    $this->breaker->recordFailure(Channel::Push);

    expect($this->breaker->state(Channel::Push))->toBe(CircuitState::Open);

    usleep(1_100_000);
    $this->breaker->allowRequest(Channel::Push);
    $this->breaker->recordSuccess(Channel::Push);

    expect($this->breaker->state(Channel::Push))->toBe(CircuitState::Closed);
});

it('holds jobs back while the circuit is open', function (): void {
    foreach (range(1, 3) as $_) {
        $this->breaker->recordFailure(Channel::Sms);
    }

    $job = (new SendNotification('id', Channel::Sms))->withFakeQueueInteractions();
    $ran = false;
    (new CheckCircuitBreaker($this->breaker))->handle($job, function () use (&$ran): void {
        $ran = true;
    });

    expect($ran)->toBeFalse();
    $job->assertReleased();
});

it('is fed by delivery outcomes, ignoring permanent failures', function (): void {
    $this->app->instance(RetryPolicy::class, new RetryPolicy(5, 10, 3, 900, 0.0));
    Http::fakeSequence()
        ->push(['message' => 'bad number'], 400)
        ->push(['message' => 'bad number'], 400)
        ->push(['message' => 'bad number'], 400)
        ->push(null, 503)
        ->push(null, 503)
        ->push(null, 503);

    $deliver = app(DeliverNotification::class);
    foreach (range(1, 3) as $_) {
        $deliver->handle(Notification::factory()->status(NotificationStatus::Queued)->create()->id);
    }
    expect($this->breaker->state(Channel::Sms))->toBe(CircuitState::Closed);

    foreach (range(1, 3) as $_) {
        $deliver->handle(Notification::factory()->status(NotificationStatus::Queued)->create()->id);
    }
    expect($this->breaker->state(Channel::Sms))->toBe(CircuitState::Open);
});
