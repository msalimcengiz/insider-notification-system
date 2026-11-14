<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Notification
 */
final class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'channel' => $this->channel,
            'priority' => $this->priority,
            'recipient' => $this->recipient,
            'subject' => $this->subject,
            'content' => $this->content,
            'template_id' => $this->template_id,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'provider_message_id' => $this->provider_message_id,
            'last_error' => $this->last_error,
            'correlation_id' => $this->correlation_id,
            'scheduled_at' => $this->scheduled_at?->toIso8601ZuluString(),
            'queued_at' => $this->queued_at?->toIso8601ZuluString(),
            'sent_at' => $this->sent_at?->toIso8601ZuluString(),
            'failed_at' => $this->failed_at?->toIso8601ZuluString(),
            'cancelled_at' => $this->cancelled_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'updated_at' => $this->updated_at->toIso8601ZuluString(),
        ];
    }
}
