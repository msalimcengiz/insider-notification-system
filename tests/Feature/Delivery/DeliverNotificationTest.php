<?php

declare(strict_types=1);

use App\Actions\DeliverNotification;
use App\Enums\AttemptOutcome;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Services\Delivery\ProviderRegistry;
use App\Services\Delivery\RetryPolicy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const PROVIDER_URL = 'https://provider.test/notify';

beforeEach(function (): void {
    // Deterministic backoff: 10s, 30s, 90s, ... without jitter.
    $this->app->instance(RetryPolicy::class, new RetryPolicy(3, 10, 3, 900, 0.0));
});

function accepted(string $messageId = 'msg-1'): array
{
    return [PROVIDER_URL => Http::response(['messageId' => $messageId, 'status' => 'accepted', 'timestamp' => now()->toIso8601String()], 202)];
}

it('sends the documented payload and marks the notification as sent', function (): void {
    Http::fake(accepted('msg-42'));
    $notification = Notification::factory()->status(NotificationStatus::Queued)->create([
        'recipient' => '+905551234567',
        'content' => 'Your message',
        'correlation_id' => 'trace-1',
    ]);

    $retryIn = app(DeliverNotification::class)->handle($notification->id);

    expect($retryIn)->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->url() === PROVIDER_URL
        && $request->method() === 'POST'
        && $request->data() === ['to' => '+905551234567', 'channel' => 'sms', 'content' => 'Your message']
        && $request->header('Idempotency-Key') === [$notification->id]
        && $request->header('X-Correlation-ID') === ['trace-1']);

    $notification->refresh();
    expect($notification->status)->toBe(NotificationStatus::Sent)
        ->and($notification->provider_message_id)->toBe('msg-42')
        ->and($notification->attempts)->toBe(1)
        ->and($notification->sent_at)->not->toBeNull()
        ->and($notification->deliveryAttempts()->sole()->outcome)->toBe(AttemptOutcome::Success);
});

it('schedules a retry with backoff on a retryable failure', function (): void {
    Http::fake([PROVIDER_URL => Http::response(['message' => 'busy'], 503)]);
    $notification = Notification::factory()->status(NotificationStatus::Queued)->create();

    $retryIn = app(DeliverNotification::class)->handle($notification->id);

    $notification->refresh();
    expect($retryIn)->toBe(10)
        ->and($notification->status)->toBe(NotificationStatus::Retrying)
        ->and($notification->attempts)->toBe(1)
        ->and($notification->last_error)->toBe('Provider answered 503. busy')
        ->and($notification->next_attempt_at?->diffInSeconds(now(), true))->toBeBetween(9.0, 11.0);
});

it('retries a retrying notification and succeeds', function (): void {
    Http::fake(accepted());
    $notification = Notification::factory()->status(NotificationStatus::Retrying)->create(['attempts' => 1]);

    app(DeliverNotification::class)->handle($notification->id);

    expect($notification->refresh()->status)->toBe(NotificationStatus::Sent)
        ->and($notification->attempts)->toBe(2);
});

it('fails permanently on a non-retryable error without retrying', function (): void {
    Http::fake([PROVIDER_URL => Http::response(['message' => 'Invalid number'], 400)]);
    $notification = Notification::factory()->status(NotificationStatus::Queued)->create();

    $retryIn = app(DeliverNotification::class)->handle($notification->id);

    expect($retryIn)->toBeNull()
        ->and($notification->refresh()->status)->toBe(NotificationStatus::Failed)
        ->and($notification->failed_at)->not->toBeNull()
        ->and($notification->deliveryAttempts()->sole()->outcome)->toBe(AttemptOutcome::PermanentFailure);
});

it('gives up when the retry budget is exhausted', function (): void {
    Http::fake([PROVIDER_URL => Http::response(null, 500)]);
    $notification = Notification::factory()->status(NotificationStatus::Retrying)->create(['attempts' => 2]);

    $retryIn = app(DeliverNotification::class)->handle($notification->id);

    expect($retryIn)->toBeNull()
        ->and($notification->refresh()->status)->toBe(NotificationStatus::Failed)
        ->and($notification->attempts)->toBe(3);
});

it('retries timeouts and connection errors', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
    $notification = Notification::factory()->status(NotificationStatus::Queued)->create();

    expect(app(DeliverNotification::class)->handle($notification->id))->toBe(10)
        ->and($notification->refresh()->status)->toBe(NotificationStatus::Retrying)
        ->and($notification->last_error)->toContain('timed out');
});

it('honours Retry-After on 429', function (): void {
    Http::fake([PROVIDER_URL => Http::response(null, 429, ['Retry-After' => '45'])]);
    $notification = Notification::factory()->status(NotificationStatus::Queued)->create();

    expect(app(DeliverNotification::class)->handle($notification->id))->toBe(45);
});

it('skips notifications that are no longer claimable', function (NotificationStatus $status): void {
    Http::fake();
    $notification = Notification::factory()->status($status)->create();

    expect(app(DeliverNotification::class)->handle($notification->id))->toBeNull();

    Http::assertNothingSent();
    expect($notification->refresh()->status)->toBe($status);
})->with([
    'cancelled before the worker got it' => [NotificationStatus::Cancelled],
    'already sent (duplicate job)' => [NotificationStatus::Sent],
    'claimed by another worker' => [NotificationStatus::Processing],
    'scheduled' => [NotificationStatus::Scheduled],
]);

it('records every attempt in order', function (): void {
    Http::fakeSequence(PROVIDER_URL)
        ->push(null, 503)
        ->push(null, 502)
        ->push(['messageId' => 'm'], 202);
    $notification = Notification::factory()->status(NotificationStatus::Queued)->create();

    foreach (range(1, 3) as $_) {
        app(DeliverNotification::class)->handle($notification->id);
    }

    expect($notification->deliveryAttempts()->pluck('http_status')->all())->toBe([503, 502, 202])
        ->and($notification->refresh()->status)->toBe(NotificationStatus::Sent);
});

it('uses the per-channel provider URL when configured', function (): void {
    config(['notifications.provider.channels.email.url' => 'https://email-provider.test/send']);
    $this->app->forgetInstance(ProviderRegistry::class);
    Http::fake(['https://email-provider.test/send' => Http::response(['messageId' => 'e'], 202)]);
    $notification = Notification::factory()->email()->status(NotificationStatus::Queued)->create();

    app(DeliverNotification::class)->handle($notification->id);

    expect($notification->refresh()->status)->toBe(NotificationStatus::Sent);
});
