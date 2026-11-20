<?php

namespace OneOakDigital\CommonStats;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use OneOakDigital\CommonStats\Contracts\StatsStore;
use OneOakDigital\CommonStats\Services\StatsManager;
use OneOakDigital\CommonStats\Services\StatsMetrics;
use OneOakDigital\CommonStats\Services\StatsReader;
use OneOakDigital\CommonStats\Services\StatsRecorder;
use OneOakDigital\CommonStats\Stores\ArrayStore;
use OneOakDigital\CommonStats\Stores\RedisStore;

/**
 * Registers the store (common-stats.store), the services and the
 * CommonStats facade, and the migration. Nothing is scheduled: the
 * application flushes the stats it wants to store, when it wants.
 */
class CommonStatsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/common-stats.php', 'common-stats');

        $this->app->singleton(StatsStore::class, fn (Application $app): StatsStore => match ($app['config']->get('common-stats.store', 'redis')) {
            'array' => new ArrayStore,
            default => new RedisStore($app->make('redis'), (array) $app['config']->get('common-stats.redis')),
        });

        $this->app->singleton(StatsMetrics::class);
        $this->app->singleton(StatsReader::class);
        $this->app->singleton(StatsManager::class);

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
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/common-stats.php' => $this->app->configPath('common-stats.php'),
            ], 'common-stats-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'common-stats-migrations');
        }
    }
}
