<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redis
    |--------------------------------------------------------------------------
    |
    | Metric values are aggregated per minute and per hour in this Redis
    | connection (config/database.php "redis"), under "prefix" (on top of the
    | connection's own prefix). An aggregate the jobs never store expires
    | after "ttl_hours".
    |
    */

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
