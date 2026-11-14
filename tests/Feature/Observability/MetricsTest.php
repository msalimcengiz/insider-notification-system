<?php

declare(strict_types=1);

use App\Actions\DeliverNotification;
use App\Enums\AttemptOutcome;
use App\Enums\Channel;
use App\Enums\NotificationStatus;
use App\Jobs\SendNotification;
use App\Models\Notification;
use App\Services\Delivery\RetryPolicy;
use App\Services\Metrics\MetricsRecorder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->flushRedis();
    $this->app->instance(RetryPolicy::class, new RetryPolicy(3, 10, 3, 900, 0.0));
});

it('records outcomes and latency per channel', function (): void {
    $recorder = app(MetricsRecorder::class);
    $recorder->recordAttempt(Channel::Sms, AttemptOutcome::Success, 80, NotificationStatus::Sent, 1500);
    $recorder->recordAttempt(Channel::Sms, AttemptOutcome::RetryableFailure, 300, NotificationStatus::Retrying);
    $recorder->recordAttempt(Channel::Email, AttemptOutcome::PermanentFailure, 20, NotificationStatus::Failed);

    $sms = $recorder->totals(Channel::Sms, 5);

    expect($sms['sent'])->toBe(1)
        ->and($sms['retrying'])->toBe(1)
        ->and($sms['attempts:success'])->toBe(1)
        ->and($sms['attempts:retryable_failure'])->toBe(1)
        ->and($sms['provider_ms:count'])->toBe(2)
        ->and($sms['provider_ms:sum'])->toBe(380)
        ->and($sms['e2e_ms:count'])->toBe(1)
        ->and($recorder->totals(Channel::Email, 5)['failed'])->toBe(1);
});

it('is fed by real delivery attempts', function (): void {
    Http::fakeSequence()->push(['messageId' => 'a'], 202)->push(null, 503)->push(['message' => 'no'], 400);

    foreach (range(1, 3) as $_) {
        app(DeliverNotification::class)->handle(Notification::factory()->status(NotificationStatus::Queued)->create()->id);
    }

    $totals = app(MetricsRecorder::class)->totals(Channel::Sms, 1);
    expect($totals['sent'])->toBe(1)
        ->and($totals['retrying'])->toBe(1)
        ->and($totals['failed'])->toBe(1)
        ->and($totals['provider_ms:count'])->toBe(3);
});

it('reports rates, latency, queue depth and in-flight counts', function (): void {
    $recorder = app(MetricsRecorder::class);
    foreach (range(1, 9) as $_) {
        $recorder->recordAttempt(Channel::Sms, AttemptOutcome::Success, 40, NotificationStatus::Sent, 900);
    }
    $recorder->recordAttempt(Channel::Sms, AttemptOutcome::PermanentFailure, 40, NotificationStatus::Failed);

    Queue::connection('redis')->pushOn('notifications-sms-high', new SendNotification('x', Channel::Sms));
    Notification::factory()->count(2)->status(NotificationStatus::Retrying)->create();
    Notification::factory()->sent()->create();

    $response = $this->getJson('/api/v1/metrics?window=5')->assertOk();

    expect($response->json('window_minutes'))->toBe(5)
        ->and($response->json('overall.sent'))->toBe(9)
        ->and($response->json('overall.success_rate'))->toBe(0.9)
        ->and($response->json('channels.sms.attempts.failure_rate'))->toBe(0.1)
        ->and($response->json('channels.sms.latency_ms.provider.avg'))->toEqual(40)
        ->and($response->json('channels.sms.latency_ms.provider.p95'))->toBeGreaterThan(25)->toBeLessThanOrEqual(50)
        ->and($response->json('channels.sms.latency_ms.end_to_end.p50'))->toBeGreaterThan(500)->toBeLessThanOrEqual(1000)
        ->and($response->json('channels.sms.queues.high.pending'))->toBe(1)
        ->and($response->json('channels.sms.queues.high.oldest_pending_age_seconds'))->toBeInt()
        ->and($response->json('channels.sms.circuit'))->toBe('closed')
        ->and($response->json('channels.email.success_rate'))->toBeNull()
        ->and($response->json('notifications_in_flight.retrying'))->toBe(2);
});

it('validates the window', function (): void {
    $this->getJson('/api/v1/metrics?window=0')->assertUnprocessable()->assertJsonValidationErrors(['window']);
    $this->getJson('/api/v1/metrics?window=61')->assertUnprocessable();
});
