<?php

namespace OneOakDigital\CommonStats;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use OneOakDigital\CommonStats\Contracts\StatsStore;
use OneOakDigital\CommonStats\Services\StatsFlusher;
use OneOakDigital\CommonStats\Services\StatsManager;
use OneOakDigital\CommonStats\Services\StatsReader;
use OneOakDigital\CommonStats\Services\StatsRecorder;
use OneOakDigital\CommonStats\Stores\ArrayStore;
use OneOakDigital\CommonStats\Stores\RedisStore;

/**
 * Registers the store (common-stats.store), the services and the
 * CommonStats facade. Nothing is persisted or scheduled: the application
 * flushes the stats it wants to keep, when it wants, and stores them itself.
 */
class CommonStatsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/common-stats.php', 'common-stats');

        $this->app->singleton(StatsStore::class, function (Application $app): StatsStore {
            $channels = self::channels($app);

            return match ($app['config']->get('common-stats.store', 'redis')) {
                'array' => new ArrayStore($channels),
                default => new RedisStore($app->make('redis'), (array) $app['config']->get('common-stats.redis'), $channels),
            };
        });

        $this->app->singleton(StatsReader::class);
        $this->app->singleton(StatsManager::class);
        $this->app->singleton(StatsFlusher::class, fn (Application $app): StatsFlusher => new StatsFlusher($app->make(StatsStore::class), self::channels($app)));

        $this->app->singleton(
            StatsRecorder::class,
            fn (Application $app): StatsRecorder => new StatsRecorder(
                $app->make(StatsStore::class),
                (bool) $app['config']->get('common-stats.fail_silently', true),
            ),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/common-stats.php' => $this->app->configPath('common-stats.php'),
            ], 'common-stats-config');
        }
    }

    /**
     * The flush channels (common-stats.channels).
     *
     * @return list<string>
     */
    private static function channels(Application $app): array
    {
        return array_values(array_map(strval(...), (array) $app['config']->get('common-stats.channels', [])));
    }
}
