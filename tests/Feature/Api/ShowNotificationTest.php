<?php

declare(strict_types=1);

use App\Enums\AttemptOutcome;
use App\Models\Notification;
use App\Models\NotificationAttempt;

it('returns a notification with its delivery attempts', function (): void {
    $notification = Notification::factory()->sent()->create();
    NotificationAttempt::query()->create([
        'notification_id' => $notification->id,
        'attempt_number' => 1,
        'outcome' => AttemptOutcome::Success,
        'http_status' => 202,
        'duration_ms' => 87,
    ]);

    $this->getJson("/api/v1/notifications/{$notification->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $notification->id)
        ->assertJsonPath('data.status', 'sent')
        ->assertJsonPath('data.provider_message_id', $notification->provider_message_id)
        ->assertJsonPath('data.delivery_attempts.0.outcome', 'success')
        ->assertJsonPath('data.delivery_attempts.0.http_status', 202);
});

it('returns 404 for unknown and malformed IDs without leaking internals', function (string $id): void {
    $this->getJson("/api/v1/notifications/{$id}")
        ->assertNotFound()
        ->assertExactJson(['message' => 'Resource not found.']);
})->with([
    'unknown uuid' => ['01a1da6f-bfb3-72cd-839d-47dbb5f84cc7'],
    'not a uuid' => ['42'],
]);
