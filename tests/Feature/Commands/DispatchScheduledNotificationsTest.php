<?php

declare(strict_types=1);

use App\Enums\NotificationStatus;
use App\Jobs\SendNotification;
use App\Models\Notification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

it('queues scheduled notifications that are due', function (): void {
    $due = Notification::factory()->scheduledAt(now()->subSecond())->create();
    $later = Notification::factory()->scheduledAt(now()->addHour())->create();

    $this->artisan('notifications:dispatch-scheduled')->assertSuccessful();

    expect($due->refresh()->status)->toBe(NotificationStatus::Queued)
        ->and($later->refresh()->status)->toBe(NotificationStatus::Scheduled);
    Queue::assertPushed(SendNotification::class, fn (SendNotification $job): bool => $job->notificationId === $due->id);
    Queue::assertPushed(SendNotification::class, 1);
});

it('leaves cancelled scheduled notifications alone', function (): void {
    Notification::factory()->scheduledAt(now()->subMinute())->create(['status' => NotificationStatus::Cancelled]);

    $this->artisan('notifications:dispatch-scheduled')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('respects the per-run limit', function (): void {
    Notification::factory()->count(5)->scheduledAt(now()->subSecond())->create();

    $this->artisan('notifications:dispatch-scheduled', ['--limit' => 3])->assertSuccessful();

    Queue::assertPushed(SendNotification::class, 3);
});

it('is scheduled every ten seconds', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'notifications:dispatch-scheduled'));

    expect($event)->not->toBeNull()
        ->and($event->repeatSeconds)->toBe(10);
});
