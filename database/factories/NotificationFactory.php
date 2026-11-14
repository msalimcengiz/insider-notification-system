<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Channel;
use App\Enums\NotificationStatus;
use App\Enums\Priority;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'channel' => Channel::Sms,
            'priority' => Priority::Normal,
            'recipient' => '+90555'.fake()->numerify('#######'),
            'content' => fake()->sentence(),
            'status' => NotificationStatus::Pending,
        ];
    }

    public function sms(): static
    {
        return $this->state(fn (): array => [
            'channel' => Channel::Sms,
            'recipient' => '+90555'.fake()->numerify('#######'),
            'subject' => null,
        ]);
    }

    public function email(): static
    {
        return $this->state(fn (): array => [
            'channel' => Channel::Email,
            'recipient' => fake()->unique()->safeEmail(),
            'subject' => fake()->sentence(4),
        ]);
    }

    public function push(): static
    {
        return $this->state(fn (): array => [
            'channel' => Channel::Push,
            'recipient' => fake()->sha256(),
            'subject' => null,
        ]);
    }

    public function priority(Priority $priority): static
    {
        return $this->state(fn (): array => ['priority' => $priority]);
    }

    public function status(NotificationStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function scheduledAt(\DateTimeInterface $at): static
    {
        return $this->state(fn (): array => [
            'status' => NotificationStatus::Scheduled,
            'scheduled_at' => $at,
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => NotificationStatus::Sent,
            'attempts' => 1,
            'provider_message_id' => fake()->uuid(),
            'sent_at' => now(),
        ]);
    }
}
