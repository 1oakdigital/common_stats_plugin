<?php

namespace OneOakDigital\CommonStats;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use OneOakDigital\CommonStats\Contracts\StatsStore;
use OneOakDigital\CommonStats\Jobs\FlushHourStats;
use OneOakDigital\CommonStats\Jobs\FlushMinuteStats;
use OneOakDigital\CommonStats\Services\StatsManager;
use OneOakDigital\CommonStats\Services\StatsMetrics;
use OneOakDigital\CommonStats\Services\StatsReader;
use OneOakDigital\CommonStats\Services\StatsRecorder;
use OneOakDigital\CommonStats\Stores\ArrayStore;
use OneOakDigital\CommonStats\Stores\RedisStore;

/**
 * Registers the store (common-stats.store), the recorder, reader and the
 * CommonStats facade, the migrations and, unless common-stats.schedule is
 * off, the minute and hour jobs in the scheduler.
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

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if ( ! $this->app['config']->get('common-stats.schedule', true)) {
                return;
            }

            $schedule->job(FlushMinuteStats::class)->everyMinute()->withoutOverlapping()->onOneServer();
            $schedule->job(FlushHourStats::class)->hourlyAt(1)->withoutOverlapping()->onOneServer();
        });
    }
}
