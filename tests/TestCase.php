<?php

namespace OneOakDigital\CommonStats\Tests;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use OneOakDigital\CommonStats\CommonStatsServiceProvider;
use OneOakDigital\CommonStats\Contracts\StatsStore;
use OneOakDigital\CommonStats\Facades\CommonStats;
use OneOakDigital\CommonStats\Services\StatsFlusher;
use OneOakDigital\CommonStats\Services\StatsManager;
use OneOakDigital\CommonStats\Services\StatsMetrics;
use OneOakDigital\CommonStats\Services\StatsReader;
use OneOakDigital\CommonStats\Services\StatsRecorder;
use OneOakDigital\CommonStats\Stores\RedisStore;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Runs against a real Redis (REDIS_HOST, default 127.0.0.1) in database
 * COMMON_STATS_TEST_REDIS_DB (default 15), which is emptied before and after
 * every test, with a Laravel connection prefix set. The database is SQLite,
 * or DB_CONNECTION (mysql, pgsql) with DB_HOST, DB_PORT, DB_DATABASE,
 * DB_USERNAME, DB_PASSWORD.
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
     * @return array<string, array{string}>
     */
    public static function storeProvider(): array
    {
        return ['redis (phpredis)' => ['phpredis'], 'redis (predis)' => ['predis'], 'array' => ['array']];
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

        $connection = env('DB_CONNECTION');

        $app['config']->set('database.default', in_array($connection, ['mysql', 'pgsql'], true) ? $connection : 'testing');
    }

    /**
     * Use the array store, or the Redis store with this client (phpredis or
     * predis), for the rest of the test.
     */
    protected function useStore(string $store): void
    {
        config('array' === $store
            ? ['common-stats.store' => 'array']
            : ['common-stats.store' => 'redis', 'database.redis.client' => $store]);

        foreach (['redis', 'redis.connection', StatsStore::class, StatsMetrics::class, StatsReader::class, StatsRecorder::class, StatsManager::class, StatsFlusher::class] as $abstract) {
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

    protected function redisStore(): RedisStore
    {
        return new RedisStore($this->app->make('redis'), (array) config('common-stats.redis'));
    }
}
