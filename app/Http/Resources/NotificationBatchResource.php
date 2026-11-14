<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\NotificationStatus;
use App\Models\NotificationBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotificationBatch
 */
final class NotificationBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $counts = $this->statusCounts();
        $total = array_sum($counts);

        $finished = 0;
        foreach (NotificationStatus::cases() as $status) {
            if ($status->isFinal()) {
                $finished += $counts[$status->value];
            }
        }

        return [
            'id' => $this->id,
            'total' => $total,
            'finished' => $finished,
            'progress' => $total === 0 ? 100.0 : round($finished / $total * 100, 2),
            'status_counts' => $counts,
            'correlation_id' => $this->correlation_id,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
