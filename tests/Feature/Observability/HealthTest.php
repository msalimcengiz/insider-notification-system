<?php

declare(strict_types=1);

use App\Enums\Channel;
use App\Services\CircuitBreaker\CircuitBreaker;
use App\Services\Health\HealthCheck;
use Illuminate\Contracts\Redis\Factory as Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

beforeEach(function (): void {
    $this->flushRedis();
});

function fakeHorizon(array $masters): void
{
    $repository = Mockery::mock(MasterSupervisorRepository::class);
    $repository->allows('all')->andReturn($masters);
    app()->instance(MasterSupervisorRepository::class, $repository);
}

it('is ok when every dependency is up', function (): void {
    fakeHorizon([(object) ['status' => 'running']]);

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database.status', 'ok')
        ->assertJsonPath('checks.redis.status', 'ok')
        ->assertJsonPath('checks.workers.running', 1)
        ->assertJsonPath('checks.circuits.channels.sms', 'closed');
});

it('is degraded when no Horizon master is running', function (): void {
    fakeHorizon([]);

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.workers.status', 'down');
});

it('is degraded when a circuit is open', function (): void {
    fakeHorizon([(object) ['status' => 'running']]);
    $breaker = new CircuitBreaker(app(Redis::class), 1, 30, 60);
    $this->app->instance(CircuitBreaker::class, $breaker);
    $breaker->recordFailure(Channel::Push);

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.circuits.channels.push', 'open');
});

it('is down with 503 when Redis is unreachable', function (): void {
    fakeHorizon([(object) ['status' => 'running']]);
    $redis = Mockery::mock(Redis::class);
    $redis->allows('connection')->andThrow(new RuntimeException('Connection refused'));
    $this->app->instance(Redis::class, $redis);
    $this->app->forgetInstance(CircuitBreaker::class);
    $this->app->forgetInstance(HealthCheck::class);

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'down')
        ->assertJsonPath('checks.redis.status', 'down')
        ->assertJsonPath('checks.workers.status', 'unknown');
});
