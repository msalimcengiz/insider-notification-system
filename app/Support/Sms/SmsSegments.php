<?php

declare(strict_types=1);

namespace App\Support\Sms;

final readonly class SmsSegments
{
    public function __construct(
        public SmsEncoding $encoding,
        /** Length in encoding units (GSM-7 septets or UCS-2 code units). */
        public int $units,
        public int $segments,
    ) {}

    /**
     * Largest number of encoding units that fits in the given number of segments.
     */
    public function capacityFor(int $segments): int
    {
        return $segments <= 1
            ? $this->encoding->singleSegmentLimit()
            : $segments * $this->encoding->multiSegmentLimit();
    }
}
