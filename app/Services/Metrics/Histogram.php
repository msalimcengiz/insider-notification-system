<?php

declare(strict_types=1);

namespace App\Services\Metrics;

/**
 * Fixed-bucket histogram, the same idea as a Prometheus histogram: cheap to
 * record (one counter increment), mergeable across workers and time buckets,
 * and good enough for percentiles via linear interpolation inside a bucket.
 */
final readonly class Histogram
{
    /**
     * @param  list<int>  $bounds  ascending upper bounds; values above the last go to "+Inf"
     */
    public function __construct(public array $bounds) {}

    public function bucketFor(int $value): string
    {
        foreach ($this->bounds as $bound) {
            if ($value <= $bound) {
                return (string) $bound;
            }
        }

        return '+Inf';
    }

    /**
     * @param  array<string, int>  $counts  bucket => count (non-cumulative)
     */
    public function quantile(array $counts, float $q): ?float
    {
        $total = array_sum($counts);

        if ($total === 0) {
            return null;
        }

        $rank = $q * $total;
        $cumulative = 0;
        $lower = 0;

        foreach ([...$this->bounds, '+Inf'] as $bound) {
            $inBucket = $counts[(string) $bound] ?? 0;

            if ($inBucket > 0 && $cumulative + $inBucket >= $rank) {
                if ($bound === '+Inf') {
                    // No upper bound to interpolate to: report the last finite one.
                    return (float) $this->bounds[count($this->bounds) - 1];
                }

                return round($lower + ($bound - $lower) * (($rank - $cumulative) / $inBucket), 1);
            }

            $cumulative += $inBucket;
            $lower = $bound === '+Inf' ? $lower : $bound;
        }

        return (float) $this->bounds[count($this->bounds) - 1];
    }
}
