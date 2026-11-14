<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\NotificationData;
use App\Enums\NotificationStatus;
use App\Exceptions\IdempotencyKeyConflict;
use App\Models\Notification;
use Illuminate\Database\UniqueConstraintViolationException;

final readonly class CreateNotification
{
    /**
     * @return array{0: Notification, 1: bool} the notification and whether it
     *                                         was replayed from an earlier request with the same idempotency key
     *
     * @throws IdempotencyKeyConflict
     */
    public function handle(NotificationData $data, ?string $idempotencyKey = null, ?string $correlationId = null): array
    {
        if ($idempotencyKey !== null) {
            $existing = Notification::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return [$this->replay($existing, $data, $idempotencyKey), true];
            }
        }

        try {
            $notification = Notification::query()->create([
                'channel' => $data->channel,
                'priority' => $data->priority,
                'recipient' => $data->recipient,
                'subject' => $data->subject,
                'content' => $data->content,
                'template_id' => $data->templateId,
                'template_variables' => $data->templateVariables,
                'status' => $data->isScheduled() ? NotificationStatus::Scheduled : NotificationStatus::Pending,
                'scheduled_at' => $data->scheduledAt,
                'idempotency_key' => $idempotencyKey,
                'correlation_id' => $correlationId,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Lost a race against a concurrent request with the same key.
            $existing = $idempotencyKey !== null
                ? Notification::query()->where('idempotency_key', $idempotencyKey)->first()
                : null;

            if ($existing === null) {
                throw $e;
            }

            return [$this->replay($existing, $data, $idempotencyKey), true];
        }

        return [$notification, false];
    }

    private function replay(Notification $existing, NotificationData $data, string $key): Notification
    {
        if (! $data->matches($existing->channel->value, $existing->recipient, $existing->content)) {
            throw new IdempotencyKeyConflict($key);
        }

        return $existing;
    }
}
