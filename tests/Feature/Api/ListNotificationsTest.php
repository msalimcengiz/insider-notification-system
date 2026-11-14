<?php

declare(strict_types=1);

use App\Enums\NotificationStatus;
use App\Enums\Priority;
use App\Models\Notification;
use App\Models\NotificationBatch;

it('lists notifications newest first without attempt history', function (): void {
    $old = Notification::factory()->create(['created_at' => now()->subHour()]);
    $new = Notification::factory()->create();

    $this->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.id', $new->id)
        ->assertJsonPath('data.1.id', $old->id)
        ->assertJsonMissingPath('data.0.delivery_attempts');
});

it('filters by status, channel and priority with comma separated values', function (): void {
    Notification::factory()->sms()->status(NotificationStatus::Queued)->create();
    Notification::factory()->sms()->status(NotificationStatus::Retrying)->create();
    Notification::factory()->sms()->sent()->create();
    Notification::factory()->email()->status(NotificationStatus::Queued)->create();
    Notification::factory()->sms()->status(NotificationStatus::Queued)->priority(Priority::Low)->create();

    $response = $this->getJson('/api/v1/notifications?status=queued,retrying&channel=sms&priority=normal')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect(collect($response->json('data'))->pluck('status')->sort()->values()->all())->toBe(['queued', 'retrying']);
});

it('filters by batch and recipient', function (): void {
    $batch = NotificationBatch::factory()->create();
    Notification::factory()->count(2)->create(['batch_id' => $batch->id]);
    Notification::factory()->create(['recipient' => '+905550000001']);

    $this->getJson("/api/v1/notifications?batch_id={$batch->id}")->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/notifications?recipient=%2B905550000001')->assertJsonCount(1, 'data');
});

it('filters by creation date range', function (): void {
    Notification::factory()->create(['created_at' => '2026-01-01 10:00:00']);
    Notification::factory()->create(['created_at' => '2026-01-15 10:00:00']);
    Notification::factory()->create(['created_at' => '2026-02-01 10:00:00']);

    $this->getJson('/api/v1/notifications?from=2026-01-10T00:00:00Z&to=2026-01-31T23:59:59Z')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('paginates with a cursor', function (): void {
    Notification::factory()->count(5)->sequence(fn ($s) => ['created_at' => now()->subMinutes($s->index)])->create();

    $first = $this->getJson('/api/v1/notifications?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.per_page', 2);

    $next = $first->json('links.next');
    expect($next)->toBeString()->toContain('per_page=2');

    $second = $this->getJson($next)->assertJsonCount(2, 'data');

    expect(array_intersect(
        array_column($first->json('data'), 'id'),
        array_column($second->json('data'), 'id'),
    ))->toBe([]);
});

it('validates filters', function (string $query, string $field): void {
    $this->getJson("/api/v1/notifications?{$query}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    ['status=queued,delivered', 'status.1'],
    ['channel=fax', 'channel.0'],
    ['per_page=500', 'per_page'],
    ['batch_id=123', 'batch_id'],
    ['from=2026-02-01&to=2026-01-01', 'to'],
]);
