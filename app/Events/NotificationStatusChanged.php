<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast over WebSocket (Reverb) whenever a notification changes status,
 * on `notifications.{id}` and, for batch items, `batches.{batch_id}`.
 *
 * Queued on its own `broadcasts` queue so a slow WebSocket server can never
 * hold up delivery workers.
 */
final class NotificationStatusChanged implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    public string $queue = 'broadcasts';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $notificationId,
        public readonly ?string $batchId,
        public readonly array $payload,
    ) {}

    public static function from(Notification $notification): self
    {
        return new self($notification->id, $notification->batch_id, [
            'id' => $notification->id,
            'batch_id' => $notification->batch_id,
            'channel' => $notification->channel->value,
            'status' => $notification->status->value,
            'attempts' => $notification->attempts,
            'provider_message_id' => $notification->provider_message_id,
            'last_error' => $notification->last_error,
            'updated_at' => $notification->updated_at->toIso8601ZuluString(),
        ]);
    }

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [new Channel("notifications.{$this->notificationId}")];

        if ($this->batchId !== null) {
            $channels[] = new Channel("batches.{$this->batchId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'notification.status';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
