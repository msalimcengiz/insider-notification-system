<?php

declare(strict_types=1);

namespace App\Enums;

enum Priority: string
{
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    /**
     * All priorities, highest first.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::High, self::Normal, self::Low];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
