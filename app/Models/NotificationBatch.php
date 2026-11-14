<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\NotificationBatchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int $total_count
 * @property string|null $idempotency_key
 * @property string|null $correlation_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class NotificationBatch extends Model
{
    /** @use HasFactory<NotificationBatchFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'total_count' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'batch_id');
    }
}
