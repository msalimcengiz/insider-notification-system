<?php

declare(strict_types=1);

use App\Enums\AttemptOutcome;
use App\Enums\Channel;
use App\Enums\NotificationStatus;
use App\Enums\Priority;
use App\Models\Notification;
use App\Models\NotificationAttempt;
use App\Models\NotificationBatch;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('persists with enum and date casts', function (): void {
    $notification = Notification::factory()->email()->priority(Priority::High)->create();

    $fresh = Notification::query()->findOrFail($notification->id);

    expect($fresh->channel)->toBe(Channel::Email)
        ->and($fresh->priority)->toBe(Priority::High)
        ->and($fresh->status)->toBe(NotificationStatus::Pending)
        ->and($fresh->attempts)->toBe(0)
        ->and($fresh->created_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($fresh->queueName())->toBe('notifications-email-high');
});

it('uses UUID primary keys', function (): void {
    expect(Notification::factory()->create()->id)->toBeUuid();
});

it('belongs to a batch', function (): void {
    $batch = NotificationBatch::factory()->create(['total_count' => 2]);
    Notification::factory()->count(2)->create(['batch_id' => $batch->id]);

    expect($batch->notifications()->count())->toBe(2);
});

it('keeps an ordered attempt history', function (): void {
    $notification = Notification::factory()->create();

    foreach ([2, 1] as $number) {
        NotificationAttempt::query()->create([
            'notification_id' => $notification->id,
            'attempt_number' => $number,
            'outcome' => AttemptOutcome::RetryableFailure,
            'http_status' => 503,
            'duration_ms' => 120,
        ]);
    }

    expect($notification->deliveryAttempts()->pluck('attempt_number')->all())->toBe([1, 2]);
});

it('enforces unique idempotency keys at the database level', function (): void {
    Notification::factory()->create(['idempotency_key' => 'order-42']);

    Notification::factory()->create(['idempotency_key' => 'order-42']);
})->throws(QueryException::class);

it('rejects unknown statuses at the database level', function (): void {
    $notification = Notification::factory()->create();

    DB::table('notifications')->where('id', $notification->id)->update(['status' => 'delivered']);
})->throws(QueryException::class);

it('filters by status with the inStatus scope', function (): void {
    Notification::factory()->create();
    Notification::factory()->status(NotificationStatus::Queued)->create();
    Notification::factory()->sent()->create();

    $count = Notification::query()
        ->inStatus([NotificationStatus::Pending, NotificationStatus::Queued])
        ->count();

    expect($count)->toBe(2);
});
