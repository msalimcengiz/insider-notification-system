<?php

declare(strict_types=1);

use App\Services\Metrics\Histogram;

beforeEach(function (): void {
    $this->histogram = new Histogram([10, 50, 100, 500]);
});

it('assigns values to the smallest bucket that fits', function (int $value, string $bucket): void {
    expect($this->histogram->bucketFor($value))->toBe($bucket);
})->with([
    [0, '10'],
    [10, '10'],
    [11, '50'],
    [100, '100'],
    [501, '+Inf'],
]);

it('returns null without samples', function (): void {
    expect($this->histogram->quantile([], 0.5))->toBeNull();
});

it('interpolates inside the bucket that holds the quantile', function (): void {
    // 10 samples in (10, 50]: the median sits halfway through that bucket.
    expect($this->histogram->quantile(['50' => 10], 0.5))->toBe(30.0);
});

it('computes tail percentiles across buckets', function (): void {
    $counts = ['10' => 90, '100' => 9, '500' => 1];

    expect($this->histogram->quantile($counts, 0.5))->toBeLessThanOrEqual(10.0)
        ->and($this->histogram->quantile($counts, 0.95))->toBeGreaterThan(50.0)->toBeLessThanOrEqual(100.0)
        ->and($this->histogram->quantile($counts, 0.999))->toBeGreaterThan(100.0)->toBeLessThanOrEqual(500.0);
});

it('caps at the last finite bound for overflow samples', function (): void {
    expect($this->histogram->quantile(['+Inf' => 3], 0.99))->toBe(500.0);
});
