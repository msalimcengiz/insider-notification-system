<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Channel;
use Carbon\CarbonImmutable;
use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Reusable message body with {{ variable }} placeholders.
 *
 * @property string $id
 * @property string $name
 * @property Channel $channel
 * @property string|null $subject
 * @property string $body
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
