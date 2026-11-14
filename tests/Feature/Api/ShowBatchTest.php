<?php

declare(strict_types=1);

use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Models\NotificationBatch;

it('summarises the progress of a batch', function (): void {
    $batch = NotificationBatch::factory()->create();
    Notification::factory()->count(2)->sent()->create(['batch_id' => $batch->id]);
    Notification::factory()->status(NotificationStatus::Failed)->create(['batch_id' => $batch->id]);
    Notification::factory()->status(NotificationStatus::Queued)->create(['batch_id' => $batch->id]);

    $this->getJson("/api/v1/batches/{$batch->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $batch->id)
        ->assertJsonPath('data.total', 4)
        ->assertJsonPath('data.finished', 3)
        ->assertJsonPath('data.progress', 75)
        ->assertJsonPath('data.status_counts.sent', 2)
        ->assertJsonPath('data.status_counts.failed', 1)
        ->assertJsonPath('data.status_counts.queued', 1)
        ->assertJsonPath('data.status_counts.pending', 0);
});

it('returns 404 for unknown batches', function (): void {
    $this->getJson('/api/v1/batches/01a1da6f-bfb3-72cd-839d-47dbb5f84cc7')->assertNotFound();
});
