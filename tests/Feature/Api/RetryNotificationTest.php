<?php

declare(strict_types=1);

use App\Actions\DeliverNotification;
use App\Enums\NotificationStatus;
use App\Jobs\SendNotification;
use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Services\Delivery\RetryPolicy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('re-queues a failed notification', function (): void {
    $notification = Notification::factory()->status(NotificationStatus::Failed)->create([
        'attempts' => 5,
        'failed_at' => now(),
        'last_error' => 'Provider answered 503.',
    ]);

    $this->postJson("/api/v1/notifications/{$notification->id}/retry")
        ->assertOk()
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.attempts', 5)
        ->assertJsonPath('data.failed_at', null);

    expect($notification->refresh()->retry_budget_start)->toBe(5);
    Queue::assertPushedOn('notifications-sms-normal', SendNotification::class);
});

it('gives a retried notification a fresh retry budget', function (): void {
    $this->app->instance(RetryPolicy::class, new RetryPolicy(3, 10, 3, 900, 0.0));
    Http::fake(['*' => Http::response(null, 503)]);
    $notification = Notification::factory()->status(NotificationStatus::Failed)->create(['attempts' => 3]);

    $this->postJson("/api/v1/notifications/{$notification->id}/retry")->assertOk();
    $retryIn = app(DeliverNotification::class)->handle($notification->id);

    // Attempt 4 overall, but the first one of the new budget: 10s backoff, not failed.
    expect($retryIn)->toBe(10)
        ->and($notification->refresh()->status)->toBe(NotificationStatus::Retrying)
        ->and($notification->attempts)->toBe(4);
});

it('only retries failed notifications', function (NotificationStatus $status): void {
    $notification = Notification::factory()->status($status)->create();

    $this->postJson("/api/v1/notifications/{$notification->id}/retry")
        ->assertConflict()
        ->assertJsonPath('status', $status->value);

    Queue::assertNothingPushed();
})->with([NotificationStatus::Sent, NotificationStatus::Queued, NotificationStatus::Cancelled]);

it('retries every failed notification of a batch', function (): void {
    $batch = NotificationBatch::factory()->create();
    Notification::factory()->count(2)->status(NotificationStatus::Failed)->create(['batch_id' => $batch->id]);
    Notification::factory()->sent()->create(['batch_id' => $batch->id]);
    Notification::factory()->status(NotificationStatus::Failed)->create();

    $this->postJson("/api/v1/batches/{$batch->id}/retry")
        ->assertOk()
        ->assertJsonPath('meta.retried', 2)
        ->assertJsonPath('data.status_counts.queued', 2)
        ->assertJsonPath('data.status_counts.sent', 1);

    Queue::assertPushed(SendNotification::class, 2);
});
