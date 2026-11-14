<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Enums\AttemptOutcome;
use App\Enums\Channel;
use App\Enums\NotificationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Redis\Connections\PhpRedisConnection;

/**
 * Delivery metrics in Redis, one hash per channel per minute.
 *
 * Workers only increment counters (a single pipelined round trip per
 * attempt), so recording never slows delivery down. Reads merge the last N
 * minute buckets, which gives success rates, throughput and latency
 * percentiles over a sliding window without a time-series database.
 */
final readonly class MetricsRecorder
{
    private const int RETENTION_SECONDS = 3 * 3600;

    public Histogram $providerLatency;

    public Histogram $endToEndLatency;

    public function __construct(private Redis $redis)
    {
        $this->providerLatency = new Histogram([10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000, 30000]);
        $this->endToEndLatency = new Histogram([100, 250, 500, 1000, 2500, 5000, 10000, 30000, 60000, 300000, 900000, 3600000]);
    }

    /**
     * @param  int|null  $endToEndMs  creation → sent, only for sent notifications
     */
    public function recordAttempt(
        Channel $channel,
        AttemptOutcome $outcome,
        int $providerMs,
        NotificationStatus $resultingStatus,
        ?int $endToEndMs = null,
    ): void {
        $key = $this->key($channel, now());

        $fields = [
            "attempts:{$outcome->value}" => 1,
            'provider_ms:count' => 1,
            'provider_ms:sum' => $providerMs,
            'provider_ms:'.$this->providerLatency->bucketFor($providerMs) => 1,
        ];

        if (in_array($resultingStatus, [NotificationStatus::Sent, NotificationStatus::Failed, NotificationStatus::Retrying], true)) {
            $fields[$resultingStatus->value] = 1;
        }

        if ($endToEndMs !== null) {
            $fields['e2e_ms:count'] = 1;
            $fields['e2e_ms:sum'] = $endToEndMs;
            $fields['e2e_ms:'.$this->endToEndLatency->bucketFor($endToEndMs)] = 1;
        }

        $this->connection()->pipeline(function ($pipe) use ($key, $fields): void {
            foreach ($fields as $field => $by) {
                $pipe->hincrby($key, $field, $by);
            }
            $pipe->expire($key, self::RETENTION_SECONDS);
        });
    }

    /**
     * Sum of every counter over the last `$minutes` minutes (current one included).
     *
     * @return array<string, int>
     */
    public function totals(Channel $channel, int $minutes): array
    {
        $now = now();
        $keys = [];
        for ($i = 0; $i < $minutes; $i++) {
            $keys[] = $this->key($channel, $now->subMinutes($i));
        }

        /** @var list<array<string, string>|false> $buckets */
        $buckets = (array) $this->connection()->pipeline(function ($pipe) use ($keys): void {
            foreach ($keys as $key) {
                $pipe->hgetall($key);
            }
        });

        $totals = [];
        foreach ($buckets as $bucket) {
            foreach ($bucket ?: [] as $field => $value) {
                $totals[$field] = ($totals[$field] ?? 0) + (int) $value;
            }
        }

        return $totals;
    }

    /**
     * @param  array<string, int>  $totals
     * @return array<string, int>
     */
    public static function histogramCounts(array $totals, string $prefix): array
    {
        $counts = [];
        foreach ($totals as $field => $value) {
            if (str_starts_with($field, "{$prefix}:") && ! in_array($field, ["{$prefix}:count", "{$prefix}:sum"], true)) {
                $counts[substr($field, strlen($prefix) + 1)] = $value;
            }
        }

        return $counts;
    }

    private function key(Channel $channel, CarbonImmutable $at): string
    {
        return "metrics:{$channel->value}:{$at->utc()->format('YmdHi')}";
    }

    private function connection(): PhpRedisConnection
    {
        /** @var PhpRedisConnection */
        return $this->redis->connection();
    }
}
