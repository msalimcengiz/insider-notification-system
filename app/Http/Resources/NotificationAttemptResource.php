<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\NotificationAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotificationAttempt
 */
final class NotificationAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'attempt' => $this->attempt_number,
            'outcome' => $this->outcome,
            'http_status' => $this->http_status,
            'error' => $this->error_message,
            'duration_ms' => $this->duration_ms,
            'attempted_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
