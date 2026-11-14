<?php

declare(strict_types=1);

use App\Enums\Channel;
use App\Enums\NotificationStatus;
use App\Jobs\Middleware\CheckCircuitBreaker;
use App\Jobs\Middleware\ThrottleChannel;
use App\Jobs\SendNotification;
use App\Models\Notification;
use App\Services\Delivery\RetryPolicy;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->flushRedis();
    $this->app->instance(RetryPolicy::class, new RetryPolicy(3, 10, 3, 900, 0.0));
});

it('releases the job with the backoff delay after a retryable failure', function (): void {
    Http::fake(['*' => Http::response(null, 503)]);
    $notification = Notification::factory()->status(NotificationStatus::Queued)->create();

    $job = (new SendNotification($notification->id, Channel::Sms))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased(10);
});

it('does not release the job when delivery is finished', function (): void {
    Http::fake(['*' => Http::response(['messageId' => 'm'], 202)]);
    $notification = Notification::factory()->status(NotificationStatus::Queued)->create();

    $job = (new SendNotification($notification->id, Channel::Sms))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertNotReleased();
    expect($notification->refresh()->status)->toBe(NotificationStatus::Sent);
});

it('runs through the queue end to end', function (): void {
    Http::fake(['*' => Http::response(['messageId' => 'm'], 202)]);
    $notification = Notification::factory()->status(NotificationStatus::Queued)->create();

    SendNotification::dispatch($notification->id, Channel::Sms);

    expect($notification->refresh()->status)->toBe(NotificationStatus::Sent);
});

it('checks the circuit, then the rate limit, and never uses the queue retry counter', function (): void {
    $job = new SendNotification('id', Channel::Sms);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(2)
        ->and($middleware[0])->toBeInstanceOf(CheckCircuitBreaker::class)
        ->and($middleware[1])->toBeInstanceOf(ThrottleChannel::class)
        ->and($job->tries)->toBe(0)
        ->and($job->retryUntil()->isFuture())->toBeTrue();
});
