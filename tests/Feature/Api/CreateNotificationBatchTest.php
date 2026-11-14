<?php

declare(strict_types=1);

use App\Jobs\SendNotification;
use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Models\Template;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

const BATCH_URL = '/api/v1/notifications/batch';

function batchItem(int $i, array $overrides = []): array
{
    return [
        'channel' => 'sms',
        'recipient' => sprintf('+90555%07d', $i),
        'content' => "Message {$i}",
        ...$overrides,
    ];
}

it('creates every notification of a batch', function (): void {
    $response = $this->postJson(BATCH_URL, ['notifications' => [
        batchItem(1),
        batchItem(2, ['channel' => 'email', 'recipient' => 'ada@example.com', 'priority' => 'low']),
        batchItem(3, ['channel' => 'push', 'recipient' => str_repeat('a1', 32)]),
    ]]);

    $response->assertAccepted()
        ->assertJsonPath('data.accepted', 3)
        ->assertJsonPath('data.duplicates', 0)
        ->assertJsonCount(3, 'data.notifications');

    $batch = NotificationBatch::query()->sole();
    expect($response->json('data.batch_id'))->toBe($batch->id)
        ->and($batch->total_count)->toBe(3)
        ->and($batch->notifications()->orderBy('batch_position')->pluck('id')->all())
        ->toBe(array_column($response->json('data.notifications'), 'id'));
});

it('queues every item on the queue of its channel and priority', function (): void {
    $this->postJson(BATCH_URL, ['notifications' => [
        batchItem(1, ['priority' => 'high']),
        batchItem(2),
        batchItem(3, ['channel' => 'email', 'recipient' => 'ada@example.com', 'priority' => 'low']),
        batchItem(4, ['scheduled_at' => now()->addHour()->toIso8601String()]),
    ]])->assertAccepted();

    Queue::assertPushedOn('notifications-sms-high', SendNotification::class);
    Queue::assertPushedOn('notifications-sms-normal', SendNotification::class);
    Queue::assertPushedOn('notifications-email-low', SendNotification::class);
    Queue::assertPushed(SendNotification::class, 3);

    expect(Notification::query()->where('status', 'queued')->count())->toBe(3)
        ->and(Notification::query()->where('status', 'scheduled')->count())->toBe(1);
});

it('does not queue duplicates or replayed batches again', function (): void {
    $this->postJson(BATCH_URL, ['notifications' => [batchItem(1, ['idempotency_key' => 'a'])]], ['Idempotency-Key' => 'b1']);
    $this->postJson(BATCH_URL, ['notifications' => [batchItem(1, ['idempotency_key' => 'a'])]], ['Idempotency-Key' => 'b1']);
    $this->postJson(BATCH_URL, ['notifications' => [batchItem(1, ['idempotency_key' => 'a'])]]);

    Queue::assertPushed(SendNotification::class, 1);
});

it('accepts the maximum batch size', function (): void {
    $items = array_map(fn (int $i): array => batchItem($i), range(1, 1000));

    $this->postJson(BATCH_URL, ['notifications' => $items])
        ->assertAccepted()
        ->assertJsonPath('data.accepted', 1000);

    expect(Notification::query()->count())->toBe(1000);
});

it('rejects more than 1000 notifications', function (): void {
    $items = array_map(fn (int $i): array => batchItem($i), range(1, 1001));

    $this->postJson(BATCH_URL, ['notifications' => $items])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['notifications']);

    expect(Notification::query()->count())->toBe(0);
});

it('rejects an empty batch', function (): void {
    $this->postJson(BATCH_URL, ['notifications' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['notifications']);
});

it('creates nothing when one item is invalid and points at it', function (): void {
    $this->postJson(BATCH_URL, ['notifications' => [
        batchItem(1),
        batchItem(2, ['recipient' => 'invalid']),
        batchItem(3, ['channel' => 'pigeon']),
    ]])->assertUnprocessable()
        ->assertJsonValidationErrors(['notifications.1.recipient', 'notifications.2.channel'])
        ->assertJsonMissingValidationErrors(['notifications.0.recipient']);

    expect(Notification::query()->count())->toBe(0);
});

it('replays the batch when the Idempotency-Key header is repeated', function (): void {
    $payload = ['notifications' => [batchItem(1), batchItem(2)]];

    $first = $this->postJson(BATCH_URL, $payload, ['Idempotency-Key' => 'campaign-7'])->assertAccepted();

    $this->postJson(BATCH_URL, $payload, ['Idempotency-Key' => 'campaign-7'])
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.batch_id', $first->json('data.batch_id'))
        ->assertJsonPath('data.notifications', $first->json('data.notifications'));

    expect(Notification::query()->count())->toBe(2);
});

it('skips items whose idempotency key already exists', function (): void {
    $this->postJson(BATCH_URL, ['notifications' => [
        batchItem(1, ['idempotency_key' => 'user-1']),
    ]])->assertAccepted();
    $existingId = Notification::query()->sole()->id;

    $response = $this->postJson(BATCH_URL, ['notifications' => [
        batchItem(1, ['idempotency_key' => 'user-1']),
        batchItem(2, ['idempotency_key' => 'user-2']),
    ]])->assertAccepted()
        ->assertJsonPath('data.accepted', 1)
        ->assertJsonPath('data.duplicates', 1);

    expect($response->json('data.notifications.0'))->toBe(['index' => 0, 'id' => $existingId, 'duplicate' => true])
        ->and($response->json('data.notifications.1.duplicate'))->toBeFalse()
        ->and(Notification::query()->count())->toBe(2);
});

it('rejects the same item key twice in one batch', function (): void {
    $this->postJson(BATCH_URL, ['notifications' => [
        batchItem(1, ['idempotency_key' => 'dup']),
        batchItem(2, ['idempotency_key' => 'dup']),
    ]])->assertUnprocessable()
        ->assertJsonValidationErrors(['notifications.1.idempotency_key']);
});

it('renders templates for batch items with a single template query', function (): void {
    Template::factory()->create(['name' => 'otp', 'body' => 'Code {{ code }}']);

    $items = array_map(
        fn (int $i): array => ['channel' => 'sms', 'recipient' => sprintf('+90555%07d', $i), 'template' => 'otp', 'variables' => ['code' => $i]],
        range(1, 50),
    );

    DB::enableQueryLog();
    $this->postJson(BATCH_URL, ['notifications' => $items])->assertAccepted();
    $templateQueries = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains($q['query'], 'from "templates"'));

    expect($templateQueries)->toHaveCount(1)
        ->and(Notification::query()->where('content', 'Code 7')->exists())->toBeTrue();
});
