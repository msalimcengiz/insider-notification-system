<?php

declare(strict_types=1);

namespace App\Enums;

enum Channel: string
{
    case Sms = 'sms';
    case Email = 'email';
    case Push = 'push';

    /**
     * Name of the queue that carries this channel's notifications at the given
     * priority. Every channel × priority pair gets its own queue so that a burst
     * (or a throttled backlog) on one channel never delays the others, while
     * workers can still drain queues strictly in priority order per channel.
     */
    public function queue(Priority $priority): string
    {
        return "notifications-{$this->value}-{$priority->value}";
    }

    /**
     * Queues of this channel, highest priority first.
     *
     * @return list<string>
     */
    public function queuesByPriority(): array
    {
        return array_map(
            fn (Priority $priority): string => $this->queue($priority),
            Priority::ordered(),
        );
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
