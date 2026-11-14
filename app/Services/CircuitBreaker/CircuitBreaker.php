<?php

declare(strict_types=1);

namespace App\Services\CircuitBreaker;

use App\Enums\Channel;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;

/**
 * Per-channel circuit breaker, shared by all workers through Redis.
 *
 *  closed ──(N consecutive transient failures)──► open ──(cool-down)──► half-open
 *     ▲                                             ▲                      │
 *     └──────────────(probe succeeds)───────────────┼──────────────────────┤
 *                                                   └──(probe fails)───────┘
 *
 * While a channel is open its jobs are released until the cool-down ends
 * instead of hammering a provider that is down (and burning their retry
 * budget on calls that cannot succeed). Only transient failures count:
 * a 4xx is a problem with one message, not with the provider.
 */
final readonly class CircuitBreaker
{
    public function __construct(
        private Redis $redis,
        private int $failureThreshold,
        private int $cooldownSeconds,
        private int $failureWindowSeconds,
        private int $probeTimeoutSeconds = 15,
    ) {}

    public static function fromConfig(Redis $redis): self
    {
        $config = (array) config('notifications.circuit_breaker');

        return new self(
            $redis,
            failureThreshold: (int) ($config['failure_threshold'] ?? 10),
            cooldownSeconds: (int) ($config['cooldown_seconds'] ?? 30),
            failureWindowSeconds: (int) ($config['failure_window_seconds'] ?? 60),
        );
    }

    /**
     * @return int 0 when a call may be made, otherwise seconds to wait
     */
    public function allowRequest(Channel $channel): int
    {
        $redis = $this->connection();

        $openForMs = (int) $redis->command('pttl', [$this->key($channel, 'open')]);
        if ($openForMs > 0) {
            return (int) ceil($openForMs / 1000);
        }

        if (! $this->isTripped($channel)) {
            return 0;
        }

        // Half-open: exactly one worker gets to probe the provider.
        $isProbe = $redis->command('set', [
            $this->key($channel, 'probe'), '1', ['nx', 'ex' => $this->probeTimeoutSeconds],
        ]);

        return $isProbe ? 0 : min(5, $this->cooldownSeconds);
    }

    public function recordSuccess(Channel $channel): void
    {
        $redis = $this->connection();
        $redis->command('del', [$this->key($channel, 'failures')]);

        if ($this->isTripped($channel)) {
            $redis->command('del', [$this->key($channel, 'tripped'), $this->key($channel, 'probe')]);
            Log::info('circuit.closed', ['channel' => $channel->value]);
        }
    }

    public function recordFailure(Channel $channel): void
    {
        $redis = $this->connection();

        // Late responses of calls that were in flight when the circuit opened.
        if ((int) $redis->command('exists', [$this->key($channel, 'open')]) > 0) {
            return;
        }

        if ($this->isTripped($channel)) {
            // The half-open probe failed: back to open for another cool-down.
            $this->open($channel, reason: 'probe failed');

            return;
        }

        $failures = (int) $redis->command('incr', [$this->key($channel, 'failures')]);
        $redis->command('expire', [$this->key($channel, 'failures'), $this->failureWindowSeconds]);

        if ($failures >= $this->failureThreshold) {
            $this->open($channel, reason: "{$failures} consecutive failures");
        }
    }

    public function state(Channel $channel): CircuitState
    {
        if ((int) $this->connection()->command('exists', [$this->key($channel, 'open')]) > 0) {
            return CircuitState::Open;
        }

        return $this->isTripped($channel) ? CircuitState::HalfOpen : CircuitState::Closed;
    }

    public function reset(Channel $channel): void
    {
        $this->connection()->command('del', array_map(
            fn (string $part): string => $this->key($channel, $part),
            ['open', 'tripped', 'probe', 'failures'],
        ));
    }

    private function open(Channel $channel, string $reason): void
    {
        $redis = $this->connection();
        $redis->command('set', [$this->key($channel, 'open'), '1', ['ex' => $this->cooldownSeconds]]);
        // Remembers that the circuit tripped, so the first call after the
        // cool-down is treated as a probe (half-open).
        $redis->command('set', [$this->key($channel, 'tripped'), '1', ['ex' => max(3600, $this->cooldownSeconds * 10)]]);
        $redis->command('del', [$this->key($channel, 'failures'), $this->key($channel, 'probe')]);

        Log::warning('circuit.opened', [
            'channel' => $channel->value,
            'reason' => $reason,
            'cooldown_seconds' => $this->cooldownSeconds,
        ]);
    }

    private function isTripped(Channel $channel): bool
    {
        return (int) $this->connection()->command('exists', [$this->key($channel, 'tripped')]) > 0;
    }

    private function key(Channel $channel, string $part): string
    {
        return "circuit:{$channel->value}:{$part}";
    }

    private function connection(): Connection
    {
        return $this->redis->connection();
    }
}
