<?php

namespace OneOakDigital\CommonStats;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use OneOakDigital\CommonStats\Jobs\FlushHourStats;
use OneOakDigital\CommonStats\Jobs\FlushMinuteStats;

/**
 * Registers the recorder (CommonStats facade), the migrations and, unless
 * common-stats.schedule is off, the minute and hour jobs in the scheduler.
 */
class CommonStatsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/common-stats.php', 'common-stats');

        $this->app->singleton(
            StatsRedis::class,
            fn (Application $app): StatsRedis => new StatsRedis($app->make('redis'), (array) $app['config']->get('common-stats.redis')),
        );

        $this->app->singleton(
            StatsRecorder::class,
            fn (Application $app): StatsRecorder => new StatsRecorder(
                $app->make(StatsRedis::class),
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
