<?php

declare(strict_types=1);

namespace App\Support\Sms;

enum SmsEncoding: string
{
    case Gsm7 = 'GSM-7';
    case Ucs2 = 'UCS-2';

    public function singleSegmentLimit(): int
    {
        return match ($this) {
            self::Gsm7 => 160,
            self::Ucs2 => 70,
        };
    }

    /** Characters per segment once the message is split (UDH takes the rest). */
    public function multiSegmentLimit(): int
    {
        return match ($this) {
            self::Gsm7 => 153,
            self::Ucs2 => 67,
        };
    }
}
