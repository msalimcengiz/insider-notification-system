<?php

declare(strict_types=1);

namespace App\Support\Sms;

/**
 * Works out how an SMS body will be encoded and how many segments it needs,
 * following GSM 03.38: characters from the extension table (e.g. € or {)
 * take two septets, and a single character outside the GSM-7 alphabet forces
 * the whole message into UCS-2.
 */
final class SmsSegmentCalculator
{
    private const string BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const string EXTENDED = "\f^{}\\[~]|€";

    /** @var array<int|string, true>|null lookup table (digits become int keys) */
    private static ?array $basic = null;

    /** @var array<int|string, true>|null */
    private static ?array $extended = null;

    public function calculate(string $content): SmsSegments
    {
        $septets = $this->gsm7Septets($content);

        if ($septets !== null) {
            return new SmsSegments(SmsEncoding::Gsm7, $septets, $this->segments($septets, SmsEncoding::Gsm7));
        }

        // UCS-2 works in 16-bit code units: characters outside the BMP (most
        // emoji) are surrogate pairs and take two.
        $units = intdiv(strlen((string) mb_convert_encoding($content, 'UTF-16BE', 'UTF-8')), 2);

        return new SmsSegments(SmsEncoding::Ucs2, $units, $this->segments($units, SmsEncoding::Ucs2));
    }

    /**
     * Septet count when the content is GSM-7 encodable, null otherwise.
     */
    private function gsm7Septets(string $content): ?int
    {
        self::$basic ??= array_fill_keys(mb_str_split(self::BASIC), true);
        self::$extended ??= array_fill_keys(mb_str_split(self::EXTENDED), true);

        $septets = 0;

        foreach (mb_str_split($content) as $char) {
            if (isset(self::$basic[$char])) {
                $septets++;
            } elseif (isset(self::$extended[$char])) {
                $septets += 2;
            } else {
                return null;
            }
        }

        return $septets;
    }

    private function segments(int $units, SmsEncoding $encoding): int
    {
        if ($units === 0) {
            return 0;
        }

        if ($units <= $encoding->singleSegmentLimit()) {
            return 1;
        }

        return (int) ceil($units / $encoding->multiSegmentLimit());
    }
}
