<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach a real provider by accident.
        Http::preventStrayRequests();
    }

    /**
     * Tests use their own Redis database (REDIS_DB in phpunit.xml); start
     * each test that touches Redis from a clean slate.
     */
    protected function flushRedis(): void
    {
        Redis::connection()->command('flushdb');
    }
}
