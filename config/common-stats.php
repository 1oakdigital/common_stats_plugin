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
    | Where the stats live: "redis", or "array" (the PHP process, for tests:
    | set COMMON_STATS_STORE=array in phpunit.xml when the tests have no
    | Redis). Nothing is persisted.
    |
    | Redis: this connection (config/database.php "redis"), under "prefix"
    | (on top of the connection's own prefix).
    |
    */

    'store' => env('COMMON_STATS_STORE', 'redis'),

    'redis' => [
        'connection' => env('COMMON_STATS_REDIS_CONNECTION', 'default'),
        'prefix' => 'common-stats:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Flush channels
    |--------------------------------------------------------------------------
    |
    | The channels the application flushes (CommonStats::flush($channel,
    | ...)), e.g. ["minute", "hour"]. Each has its own bookkeeping and min /
    | max window, so one never takes another's values.
    |
    */

    'channels' => [],

    /*
    |--------------------------------------------------------------------------
    | Failures
    |--------------------------------------------------------------------------
    |
    | When true, a store failure while recording is reported (report()) but
    | never thrown, so a stat never breaks a request or a job.
    |
    */

    'fail_silently' => true,

];
