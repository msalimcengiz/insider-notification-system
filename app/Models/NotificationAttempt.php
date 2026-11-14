<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttemptOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One provider call for a notification. Append-only audit trail.
 *
 * @property int $id
 * @property string $notification_id
 * @property int $attempt_number
 * @property AttemptOutcome $outcome
 * @property int|null $http_status
 * @property string|null $error_message
 * @property array<string, mixed>|null $response_body
 * @property int $duration_ms
 * @property CarbonImmutable $created_at
 */
class NotificationAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'outcome' => AttemptOutcome::class,
            'http_status' => 'integer',
            'response_body' => 'array',
            'duration_ms' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Notification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
