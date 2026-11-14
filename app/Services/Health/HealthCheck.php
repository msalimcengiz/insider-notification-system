<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Enums\Channel;
use App\Services\CircuitBreaker\CircuitBreaker;
use App\Services\CircuitBreaker\CircuitState;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Support\Facades\DB;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Health of the service and its dependencies.
 *
 *  - down      PostgreSQL or Redis unreachable: nothing works (HTTP 503)
 *  - degraded  the API works but delivery is impaired: no Horizon master
 *              running, or a channel's circuit is open (HTTP 200)
 *  - ok        everything up
 */
final readonly class HealthCheck
{
    public function __construct(
        private Redis $redis,
        private MasterSupervisorRepository $masters,
        private CircuitBreaker $circuitBreaker,
    ) {}

    /**
     * @return array{status: 'ok'|'degraded'|'down', checks: array<string, array<string, mixed>>}
     */
    public function run(): array
    {
        $checks = [
            'database' => $this->probe(fn () => DB::select('select 1')),
            'redis' => $this->probe(fn () => $this->redis->connection()->command('ping')),
        ];

        $checks['workers'] = $this->workers($checks['redis']['status'] === 'ok');
        $checks['circuits'] = $this->circuits($checks['redis']['status'] === 'ok');

        $status = match (true) {
            $checks['database']['status'] !== 'ok', $checks['redis']['status'] !== 'ok' => 'down',
            $checks['workers']['status'] !== 'ok', $checks['circuits']['status'] !== 'ok' => 'degraded',
            default => 'ok',
        };

        return ['status' => $status, 'checks' => $checks];
    }

    /**
     * @return array<string, mixed>
     */
    private function probe(callable $check): array
    {
        $startedAt = hrtime(true);

        try {
            $check();

            return ['status' => 'ok', 'latency_ms' => $this->elapsedMs($startedAt)];
        } catch (Throwable $e) {
            return ['status' => 'down', 'latency_ms' => $this->elapsedMs($startedAt), 'error' => class_basename($e).': '.$e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function workers(bool $redisUp): array
    {
        if (! $redisUp) {
            return ['status' => 'unknown'];
        }

        try {
            $masters = $this->masters->all();
        } catch (Throwable) {
            return ['status' => 'unknown'];
        }

        $running = array_values(array_filter($masters, fn (object $m): bool => ($m->status ?? null) === 'running'));

        return [
            'status' => $running === [] ? 'down' : 'ok',
            'horizon_masters' => count($masters),
            'running' => count($running),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function circuits(bool $redisUp): array
    {
        if (! $redisUp) {
            return ['status' => 'unknown'];
        }

        $states = [];
        foreach (Channel::cases() as $channel) {
            $states[$channel->value] = $this->circuitBreaker->state($channel)->value;
        }

        $open = in_array(CircuitState::Open->value, $states, true);

        return ['status' => $open ? 'degraded' : 'ok', 'channels' => $states];
    }

    private function elapsedMs(int|float $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 2);
    }
}
