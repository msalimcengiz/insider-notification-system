<?php

declare(strict_types=1);

use App\Support\Sms\SmsEncoding;
use App\Support\Sms\SmsSegmentCalculator;

beforeEach(function (): void {
    $this->calculator = new SmsSegmentCalculator;
});

it('fits 160 GSM-7 characters in one segment', function (): void {
    $sms = $this->calculator->calculate(str_repeat('a', 160));

    expect($sms->encoding)->toBe(SmsEncoding::Gsm7)
        ->and($sms->units)->toBe(160)
        ->and($sms->segments)->toBe(1);
});

it('splits longer GSM-7 messages into 153 character segments', function (int $length, int $segments): void {
    expect($this->calculator->calculate(str_repeat('a', $length))->segments)->toBe($segments);
})->with([
    [161, 2],
    [306, 2],
    [307, 3],
]);

it('counts extension table characters as two septets', function (): void {
    $sms = $this->calculator->calculate('Price: 10€ {promo}');

    expect($sms->encoding)->toBe(SmsEncoding::Gsm7)
        ->and($sms->units)->toBe(21);
});

it('switches to UCS-2 for characters outside GSM-7', function (): void {
    // "ş" and "ı" (Turkish) are not part of the GSM-7 alphabet.
    $sms = $this->calculator->calculate('Kargonuz yola çıktı, teşekkürler');

    expect($sms->encoding)->toBe(SmsEncoding::Ucs2)
        ->and($sms->segments)->toBe(1);
});

it('uses 70 / 67 character segments for UCS-2', function (int $length, int $segments): void {
    expect($this->calculator->calculate(str_repeat('ş', $length))->segments)->toBe($segments);
})->with([
    [70, 1],
    [71, 2],
    [134, 2],
    [135, 3],
]);

it('counts emoji as two UCS-2 code units', function (): void {
    expect($this->calculator->calculate('🚀')->units)->toBe(2);
});

it('reports the capacity for a number of segments', function (): void {
    $gsm = $this->calculator->calculate('a');
    $ucs = $this->calculator->calculate('ş');

    expect($gsm->capacityFor(1))->toBe(160)
        ->and($gsm->capacityFor(10))->toBe(1530)
        ->and($ucs->capacityFor(10))->toBe(670);
});
