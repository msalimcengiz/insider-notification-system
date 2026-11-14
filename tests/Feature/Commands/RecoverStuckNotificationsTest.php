<?php

declare(strict_types=1);

use App\Enums\NotificationStatus;
use App\Jobs\SendNotification;
use App\Models\Notification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

it('re-dispatches notifications stuck in each status', function (array $attributes): void {
    $stuck = Notification::factory()->create($attributes);

    $this->artisan('notifications:recover')->assertSuccessful();

    expect($stuck->refresh()->status)->toBe(NotificationStatus::Queued);
    Queue::assertPushed(SendNotification::class, 1);
})->with([
    'pending, never queued' => [['status' => NotificationStatus::Pending, 'updated_at' => now()->subMinutes(5)]],
    'queued for too long' => [['status' => NotificationStatus::Queued, 'queued_at' => now()->subHour()]],
    'worker died while processing' => [['status' => NotificationStatus::Processing, 'processing_started_at' => now()->subMinutes(10)]],
    'retry job lost' => [['status' => NotificationStatus::Retrying, 'next_attempt_at' => now()->subMinutes(10)]],
]);

it('leaves notifications that are moving normally alone', function (array $attributes): void {
    $healthy = Notification::factory()->create($attributes);

    $this->artisan('notifications:recover')->assertSuccessful();

    expect($healthy->refresh()->status)->toBe($attributes['status']);
    Queue::assertNothingPushed();
})->with([
    'fresh pending' => [['status' => NotificationStatus::Pending]],
    'recently queued' => [['status' => NotificationStatus::Queued, 'queued_at' => now()->subMinute()]],
    'processing right now' => [['status' => NotificationStatus::Processing, 'processing_started_at' => now()->subSeconds(10)]],
    'retry not due yet' => [['status' => NotificationStatus::Retrying, 'next_attempt_at' => now()->addMinute()]],
    'sent' => [['status' => NotificationStatus::Sent]],
    'failed' => [['status' => NotificationStatus::Failed]],
    'cancelled' => [['status' => NotificationStatus::Cancelled]],
    'scheduled' => [['status' => NotificationStatus::Scheduled, 'scheduled_at' => now()->addDay()]],
]);

it('is scheduled every minute', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'notifications:recover'));

    expect($event?->expression)->toBe('* * * * *');
});
