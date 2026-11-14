<?php

declare(strict_types=1);

use App\Actions\CancelNotification;
use App\Actions\DeliverNotification;
use App\Actions\RetryFailedNotifications;
use App\Enums\NotificationStatus;
use App\Events\NotificationStatusChanged;
use App\Models\Notification;
use App\Models\NotificationBatch;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->flushRedis();
    Event::fake([NotificationStatusChanged::class]);
});

it('broadcasts the outcome of a delivery attempt', function (): void {
    Http::fake(['*' => Http::response(['messageId' => 'm-1'], 202)]);
    $notification = Notification::factory()->status(NotificationStatus::Queued)->create();

    app(DeliverNotification::class)->handle($notification->id);

    Event::assertDispatched(NotificationStatusChanged::class, fn (NotificationStatusChanged $e): bool => $e->notificationId === $notification->id
        && $e->payload['status'] === 'sent'
        && $e->payload['provider_message_id'] === 'm-1');
});

it('broadcasts cancellations and manual retries', function (): void {
    Queue::fake();
    $queued = Notification::factory()->status(NotificationStatus::Queued)->create();
    $failed = Notification::factory()->status(NotificationStatus::Failed)->create();

    app(CancelNotification::class)->handle($queued);
    app(RetryFailedNotifications::class)->one($failed);

    Event::assertDispatched(NotificationStatusChanged::class, fn ($e): bool => $e->notificationId === $queued->id && $e->payload['status'] === 'cancelled');
    Event::assertDispatched(NotificationStatusChanged::class, fn ($e): bool => $e->notificationId === $failed->id && $e->payload['status'] === 'queued');
});

it('targets the notification channel and, for batch items, the batch channel', function (): void {
    $batch = NotificationBatch::factory()->create();
    $notification = Notification::factory()->create(['batch_id' => $batch->id]);

    $event = NotificationStatusChanged::from($notification);
    $channels = array_map(fn ($c): string => $c->name, $event->broadcastOn());

    expect($channels)->toBe(["notifications.{$notification->id}", "batches.{$batch->id}"])
        ->and($event->broadcastAs())->toBe('notification.status')
        ->and($event->queue)->toBe('broadcasts');
});
