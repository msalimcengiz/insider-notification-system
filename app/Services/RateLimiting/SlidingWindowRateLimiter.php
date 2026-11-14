<?php

declare(strict_types=1);

namespace App\Services\RateLimiting;

use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Str;

/**
 * Strict sliding-window rate limiter on a Redis sorted set.
 *
 * A fixed window (counter that resets every second) lets up to 2x the limit
 * through around a window boundary. Here every accepted call is stored with
 * its timestamp, so no window of `windowMs` ever contains more than `limit`
 * calls. The whole check-and-add runs in one Lua script (atomic across all
 * workers) and uses Redis' own clock, so worker clock skew does not matter.
 */
final readonly class SlidingWindowRateLimiter
{
    private const string SCRIPT = <<<'LUA'
        local key = KEYS[1]
        local limit = tonumber(ARGV[1])
        local window = tonumber(ARGV[2])
        local member = ARGV[3]

        local time = redis.call('TIME')
        local now = tonumber(time[1]) * 1000 + math.floor(tonumber(time[2]) / 1000)

        redis.call('ZREMRANGEBYSCORE', key, '-inf', now - window)

        if redis.call('ZCARD', key) < limit then
            redis.call('ZADD', key, now, member)
            redis.call('PEXPIRE', key, window)
            return 0
        end

        local oldest = redis.call('ZRANGE', key, 0, 0, 'WITHSCORES')
        return math.max(1, tonumber(oldest[2]) + window - now + 1)
        LUA;

    public function __construct(private Redis $redis, private ?string $connection = null) {}

    /**
     * Takes a slot if one is free.
     *
     * @return int 0 when the call is allowed, otherwise milliseconds until a slot frees up
     */
    public function attempt(string $key, int $limit, int $windowMs = 1000): int
    {
        /** @var PhpRedisConnection $redis */
        $redis = $this->redis->connection($this->connection);

        $result = $redis->eval(
            self::SCRIPT,
            1,
            "rate-limit:{$key}",
            $limit,
            $windowMs,
            (string) Str::uuid7(),
        );

        return is_numeric($result) ? (int) $result : 0;
    }

    public function clear(string $key): void
    {
        $this->redis->connection($this->connection)->command('del', ["rate-limit:{$key}"]);
    }
}
