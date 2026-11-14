<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NotificationBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationBatch>
 */
class NotificationBatchFactory extends Factory
{
    protected $model = NotificationBatch::class;

    public function definition(): array
    {
        return [
            'total_count' => 0,
        ];
    }
}
