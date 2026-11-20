<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stats
    |--------------------------------------------------------------------------
    |
    | The application's stat classes (extending CountStat, SumStat,
    | AverageStat, MinStat or MaxStat): the list of what it collects, and
    | the HELP text of the Prometheus output.
    |
    */

    'stats' => [],

    /*
    |--------------------------------------------------------------------------
    | Store
    |--------------------------------------------------------------------------
    |
    | Where values are aggregated before they are stored: "redis", or "array"
    | (the PHP process, for tests: set COMMON_STATS_STORE=array in
    | phpunit.xml when the tests have no Redis).
    |
    | Redis: this connection (config/database.php "redis"), under "prefix"
    | (on top of the connection's own prefix). A bucket the jobs never store
    | expires after "ttl_hours".
    |
    */

    'store' => env('COMMON_STATS_STORE', 'redis'),

    'redis' => [
        'connection' => env('COMMON_STATS_REDIS_CONNECTION', 'default'),
        'prefix' => 'common-stats:',
        'ttl_hours' => 48,
    ],

    /*
    |--------------------------------------------------------------------------
    | Jobs
    |--------------------------------------------------------------------------
    |
    | FlushMinuteStats (every minute) and FlushHourStats (hourly, at :01)
    | store the aggregates in stats_values. They are added to the scheduler
    | unless "schedule" is false, and run on "queue" (null: the default
    | queue). Minute values older than "retention.minute_days" are deleted
    | by the hourly job (0: kept); hour values are kept.
    |
    */

    'schedule' => (bool) env('COMMON_STATS_SCHEDULE', true),

    'queue' => env('COMMON_STATS_QUEUE'),

    'retention' => [
        'minute_days' => (int) env('COMMON_STATS_MINUTE_RETENTION_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Failures
    |--------------------------------------------------------------------------
    |
    | When true, a Redis failure while recording is reported (report()) but
    | never thrown, so a metric never breaks a request or a job.
    |
    */

    'fail_silently' => true,

];
