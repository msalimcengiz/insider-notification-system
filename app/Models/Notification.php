<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Channel;
use App\Enums\NotificationStatus;
use App\Enums\Priority;
use Carbon\CarbonImmutable;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string|null $batch_id
 * @property int|null $batch_position
 * @property string|null $template_id
 * @property Channel $channel
 * @property Priority $priority
 * @property string $recipient
 * @property string|null $subject
 * @property string $content
 * @property array<string, string|int|float>|null $template_variables
 * @property NotificationStatus $status
 * @property string|null $idempotency_key
 * @property string|null $correlation_id
 * @property int $attempts
 * @property string|null $provider_message_id
 * @property string|null $last_error
 * @property CarbonImmutable|null $scheduled_at
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $processing_started_at
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read NotificationBatch|null $batch
 * @property-read Template|null $template
 */
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = ['id'];

    protected $attributes = [
        'priority' => 'normal',
        'status' => 'pending',
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'priority' => Priority::class,
            'status' => NotificationStatus::class,
            'template_variables' => 'array',
            'batch_position' => 'integer',
            'attempts' => 'integer',
            'scheduled_at' => 'immutable_datetime',
            'queued_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<NotificationBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(NotificationBatch::class, 'batch_id');
    }

    /**
     * @return BelongsTo<Template, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * @return HasMany<NotificationAttempt, $this>
     */
    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(NotificationAttempt::class)->orderBy('attempt_number');
    }

    public function queueName(): string
    {
        return $this->channel->queue($this->priority);
    }

    /**
     * @param  Builder<self>  $query
     * @param  list<NotificationStatus>  $statuses
     */
    public function scopeInStatus(Builder $query, array $statuses): void
    {
        $query->whereIn('status', array_map(
            fn (NotificationStatus $status): string => $status->value,
            $statuses,
        ));
    }
}
