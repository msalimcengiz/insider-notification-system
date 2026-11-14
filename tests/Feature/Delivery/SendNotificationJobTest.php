<?php

declare(strict_types=1);

use App\Enums\Channel;
use App\Enums\NotificationStatus;
use App\Jobs\Middleware\CheckCircuitBreaker;
use App\Jobs\Middleware\SkipUnclaimable;
use App\Jobs\Middleware\ThrottleChannel;
use App\Jobs\Middleware\TraceJob;
use App\Jobs\SendNotification;
use App\Models\Notification;
use App\Services\Delivery\RetryPolicy;
use Illuminate\Pipeline\Pipeline;
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

it('traces, skips unclaimable jobs, then checks the circuit and the rate limit, never using the queue retry counter', function (): void {
    $job = new SendNotification('id', Channel::Sms);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(4)
        ->and($middleware[0])->toBeInstanceOf(TraceJob::class)
        ->and($middleware[1])->toBeInstanceOf(SkipUnclaimable::class)
        ->and($middleware[2])->toBeInstanceOf(CheckCircuitBreaker::class)
        ->and($middleware[3])->toBeInstanceOf(ThrottleChannel::class)
        ->and($job->tries)->toBe(0)
        ->and($job->retryUntil()->isFuture())->toBeTrue();
});

it('does not spend a rate limit slot on jobs it cannot claim', function (): void {
    // One slot per window and no waiting: a wasted slot would release the real job.
    config(['notifications.rate_limit.per_second' => 1, 'notifications.rate_limit.max_wait_ms' => 0]);
    $sent = Notification::factory()->sent()->create();
    $queued = Notification::factory()->status(NotificationStatus::Queued)->create();
    Http::fake(['*' => Http::response(['messageId' => 'm'], 202)]);

    // A duplicate job for an already sent notification (e.g. from the
    // recovery sweeper) runs first...
    SendNotification::dispatch($sent->id, Channel::Sms);
    // ...and the only slot of this window is still free for the real one.
    $job = (new SendNotification($queued->id, Channel::Sms))->withFakeQueueInteractions();
    app(Pipeline::class)->send($job)->through($job->middleware())->then(fn ($j) => app()->call([$j, 'handle']));

    $job->assertNotReleased();
    expect($queued->refresh()->status)->toBe(NotificationStatus::Sent);
    Http::assertSentCount(1);
});
