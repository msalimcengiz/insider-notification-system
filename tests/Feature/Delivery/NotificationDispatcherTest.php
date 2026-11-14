<?php

declare(strict_types=1);

use App\Enums\NotificationStatus;
use App\Enums\Priority;
use App\Jobs\SendNotification;
use App\Models\Notification;
use App\Services\Delivery\NotificationDispatcher;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

it('marks pending notifications as queued and pushes them to their queues', function (): void {
    $sms = Notification::factory()->sms()->priority(Priority::High)->create();
    $push = Notification::factory()->push()->priority(Priority::Low)->create();

    $count = app(NotificationDispatcher::class)->dispatch([$sms, $push]);

    expect($count)->toBe(2)
        ->and($sms->refresh()->status)->toBe(NotificationStatus::Queued)
        ->and($push->refresh()->status)->toBe(NotificationStatus::Queued);

    Queue::assertPushedOn('notifications-sms-high', SendNotification::class);
    Queue::assertPushedOn('notifications-push-low', SendNotification::class);
});

it('only dispatches notifications in an allowed status', function (): void {
    $pending = Notification::factory()->create();
    $cancelled = Notification::factory()->status(NotificationStatus::Cancelled)->create();
    $sent = Notification::factory()->sent()->create();

    $count = app(NotificationDispatcher::class)->dispatch([$pending, $cancelled, $sent]);

    expect($count)->toBe(1)
        ->and($cancelled->refresh()->status)->toBe(NotificationStatus::Cancelled);
    Queue::assertPushed(SendNotification::class, 1);
});

it('never dispatches the same notification twice', function (): void {
    $notification = Notification::factory()->create();
    $dispatcher = app(NotificationDispatcher::class);

    $dispatcher->dispatch([$notification]);
    $dispatcher->dispatch([$notification]);

    Queue::assertPushed(SendNotification::class, 1);
});

it('accepts IDs and handles large sets in chunks', function (): void {
    $ids = Notification::factory()->count(1200)->create()->pluck('id')->all();

    expect(app(NotificationDispatcher::class)->dispatch($ids))->toBe(1200);
    Queue::assertPushed(SendNotification::class, 1200);
});
