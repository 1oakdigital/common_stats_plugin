<?php

namespace OneOakDigital\CommonStats\Tests;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use OneOakDigital\CommonStats\CommonStatsServiceProvider;
use OneOakDigital\CommonStats\Facades\CommonStats;
use OneOakDigital\CommonStats\StatsRecorder;
use OneOakDigital\CommonStats\StatsRedis;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Runs against a real Redis (REDIS_HOST, default 127.0.0.1) in database
 * COMMON_STATS_TEST_REDIS_DB (default 15), which is emptied before and after
 * every test, with a Laravel connection prefix set.
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->redis()->flushdb();
    }

    protected function tearDown(): void
    {
        $this->redis()->flushdb();

        parent::tearDown();
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [CommonStatsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('database.redis.client', 'phpredis');
        $app['config']->set('database.redis.options.prefix', 'site-prefix:');
        $app['config']->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => (int) env('COMMON_STATS_TEST_REDIS_DB', 15),
        ]);
        $app['config']->set('common-stats.fail_silently', false);
    }

    /**
     * Switch the Redis client (phpredis or predis) for the rest of the test.
     */
    protected function useRedisClient(string $client): void
    {
        config(['database.redis.client' => $client]);

        foreach (['redis', 'redis.connection', StatsRedis::class, StatsRecorder::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        Redis::clearResolvedInstances();
        CommonStats::clearResolvedInstances();
    }

    /**
     * The test Redis connection ("default"), also when a test points the
     * stats at another one.
     */
    protected function redis(): Connection
    {
        return $this->app->make('redis')->connection('default');
    }

    protected function keys(): StatsRedis
    {
        return $this->app->make(StatsRedis::class);
    }
}
