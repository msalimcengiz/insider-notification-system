<?php

declare(strict_types=1);

use App\Actions\CancelNotification;
use App\Enums\NotificationStatus;
use App\Exceptions\NotificationNotCancellable;
use App\Models\Notification;
use App\Models\NotificationBatch;

it('cancels notifications that have not reached a worker', function (NotificationStatus $status): void {
    $notification = Notification::factory()->status($status)->create();

    $this->postJson("/api/v1/notifications/{$notification->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect($notification->refresh()->status)->toBe(NotificationStatus::Cancelled)
        ->and($notification->cancelled_at)->not->toBeNull();
})->with(NotificationStatus::cancellable());

it('refuses to cancel notifications that are processing or finished', function (NotificationStatus $status): void {
    $notification = Notification::factory()->status($status)->create();

    $this->postJson("/api/v1/notifications/{$notification->id}/cancel")
        ->assertConflict()
        ->assertJsonPath('status', $status->value);

    expect($notification->refresh()->status)->toBe($status);
})->with([NotificationStatus::Processing, NotificationStatus::Sent, NotificationStatus::Failed]);

it('treats cancelling twice as success', function (): void {
    $notification = Notification::factory()->status(NotificationStatus::Cancelled)->create();

    $this->postJson("/api/v1/notifications/{$notification->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

it('does not cancel when a worker claimed the notification first', function (): void {
    // The request loaded the notification while it was still queued...
    $notification = Notification::factory()->status(NotificationStatus::Queued)->create();

    // ...but a worker claimed it before the UPDATE ran.
    Notification::query()->whereKey($notification->id)->update(['status' => NotificationStatus::Processing]);

    expect(fn () => app(CancelNotification::class)->handle($notification))
        ->toThrow(NotificationNotCancellable::class);

    expect($notification->refresh()->status)->toBe(NotificationStatus::Processing);
});

it('returns 404 for unknown notifications', function (): void {
    $this->postJson('/api/v1/notifications/01a1da6f-bfb3-72cd-839d-47dbb5f84cc7/cancel')->assertNotFound();
});

it('cancels only the cancellable notifications of a batch', function (): void {
    $batch = NotificationBatch::factory()->create();
    Notification::factory()->count(3)->create(['batch_id' => $batch->id]);
    Notification::factory()->status(NotificationStatus::Processing)->create(['batch_id' => $batch->id]);
    Notification::factory()->sent()->create(['batch_id' => $batch->id]);
    $other = Notification::factory()->create();

    $this->postJson("/api/v1/batches/{$batch->id}/cancel")
        ->assertOk()
        ->assertJsonPath('meta.cancelled', 3)
        ->assertJsonPath('data.status_counts.cancelled', 3)
        ->assertJsonPath('data.status_counts.processing', 1)
        ->assertJsonPath('data.status_counts.sent', 1);

    expect($other->refresh()->status)->toBe(NotificationStatus::Pending);
});
