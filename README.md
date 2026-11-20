# Common Stats plugin

Stats for a Laravel 13 application, declared as classes and kept in memory, in the application's own Redis. Uses:
- serve them to Prometheus (`CommonStats::prometheus()`);
- read them in the application;
- flush what changed at the intervals it chooses, and store it wherever it wants.

The plugin persists nothing: no tables, no migrations, no scheduled jobs. Recording never calls another service.

## Installation

```bash
composer require 1oakdigital/common_stats_plugin
```

The service provider is auto-discovered. Tests without Redis can set `COMMON_STATS_STORE=array` in `phpunit.xml`. The
values then stay in the PHP process, with the same behaviour.

## Declaring stats

Each stat is a class that extends one of the base classes. The base class sets the stat's type. The class declares its
name, description and labels:

```php
namespace App\Stats;

use OneOakDigital\CommonStats\Stats\CountStat;

class MessagesReceived extends CountStat
{
    protected string $name = 'mailer.api.messages';

    protected string $description = 'Emails received by the messages API';

    /** Every value must have exactly these labels. */
    protected array $labels = ['result'];
}
```

List the classes in `config/common-stats.php` under `stats`. That list is the inventory of what the application
collects, and it gives the Prometheus HELP text.

| Base class | Type (`EStatType`) | Record | Value |
|---|---|---|---|
| `CountStat` | Count 10 | `::increment($labels, $by = 1)` | number of occurrences |
| `SumStat` | Sum 20 | `::add($value, $labels)` | total |
| `AverageStat` | Average 30 | `::record($value, $labels)` | mean |
| `MinStat` | Min 40 | `::record($value, $labels)` | lowest |
| `MaxStat` | Max 50 | `::record($value, $labels)` | highest |

```php
MessagesReceived::increment(['result' => 'queued']);
RequestDuration::record(182.4, ['route' => 'messages.store']);
```

Every value also counts as a sample, which is how many values were recorded.

**Validation.** These throw `InvalidArgumentException`:
- labels other than the declared ones;
- a name outside `a-z 0-9 . _ : -`;
- a label value outside `A-Z a-z 0-9 _ . : @ / + -` (1–64 characters);
- an identifier (the name plus its labels) longer than 191 characters;
- a value that isn't a finite number.

**Failures.** A Redis failure is reported but never thrown (`common-stats.fail_silently`), so a stat never breaks a
request.

`CommonStats::increment('signup.completed')` and `CommonStats::record($name, EStatType::Max, $value, $labels)` record
by name, without a class.

## How values are kept

Redis holds three things:
- **Running totals**: counts, sums, an average's sum, and every type's sample count. They only grow.
- **Min / max windows**: one per consumer, i.e. the Prometheus scrape and each flush channel. Each holds the lowest or
  highest value since that consumer last read it.
- **Per flush channel**: the totals that channel has already taken.

So the scrape and the flush channels never take values from each other. Several pods can scrape and flush the same
Redis.

## Prometheus

```php
Route::get('/metrics', fn () => response(CommonStats::prometheus([MessagesReceived::class, RequestDuration::class]), 200, [
    'Content-Type' => 'text/plain; version=0.0.4',
]));
```

`prometheus()` takes the stat classes or metric names to serve (none: all).

| Type | Exposed as |
|---|---|
| Count, Sum | counter `{name}_total`, the running total |
| Average | summary `{name}_sum` and `{name}_count` |
| Min, Max | gauge `{name}`: the lowest or highest value since the previous scrape, kept until newer values arrive |

In names, `.`, `:` and `-` become `_`. Every pod serves the same totals, so aggregate across pods with `max` in
PromQL, not `sum`. Don't cache the response: each scrape restarts the min/max window.

## Flushing

To keep stats, the application flushes a channel at its own interval and stores what it receives:

```php
// config/common-stats.php
'channels' => ['minute', 'hour'],

// routes/console.php
Schedule::call(fn () => CommonStats::flush('minute', [MessagesReceived::class], function (array $changes): void {
    foreach ($changes as $change) {       // StatChange
        MyStats::store('minute', now()->subMinute(), $change->metric->name, $change->metric->labels, $change->value, $change->samples);
    }
}))->everyMinute();
```

`flush($channel, $stats, $store)`:
- takes what changed since that channel's previous flush, for the given stat classes or metric names (all when the
  list is empty);
- passes it to `$store` as a list of `StatChange`;
- returns how many changes there were.

Each `StatChange` has `metric` (`name`, `labels`), `type`, `value`, `samples` and `sum`:

| Type | `value` |
|---|---|
| Count, Sum | the change of the total |
| Average | the mean of the new values (`sum` is their total) |
| Min, Max | the lowest or highest new value |

If `$store` throws, the changes are given back to Redis for the next flush, and the exception is rethrown. A channel
must be listed in `channels`.

## Reading

```php
CommonStats::memory();                                            // list<MemoryStat>, every metric
MessagesReceived::query()->where('result', 'queued')->memory();   // Collection<MemoryStat>
CommonStats::query('signup.completed')->memory();
```

Reading changes nothing. Each `MemoryStat` has `metric`, `type`, `value`, `samples` and `sum`:
- `value` is the running total, the running mean, or the min / max of the scrape window;
- `samples` is how many values were recorded;
- `sum` is the running sum, for an average.

## Redis keys

Keys sit under `redis.prefix` (default `common-stats:`), on top of the connection's own prefix:

| Key | Holds |
|---|---|
| `totals` | the running totals |
| `window:scrape`, `window:{channel}` | the min/max windows |
| `last:scrape` | the min/max values the last scrape read |
| `flushed:{channel}` | what each channel has taken |

Every change is a single atomic Lua script. Nothing expires. A stat whose label values keep changing (an id, a
timestamp) grows the hashes without limit, so keep label values to a small set.

## Configuration

```bash
php artisan vendor:publish --tag=common-stats-config
```

| Key | Env | Default | |
|-----|-----|---------|---|
| `stats` | | `[]` | The application's stat classes |
| `store` | `COMMON_STATS_STORE` | `redis` | `redis`, or `array` for tests |
| `redis.connection` | `COMMON_STATS_REDIS_CONNECTION` | `default` | Redis connection from `config/database.php` |
| `redis.prefix` | | `common-stats:` | Key prefix |
| `channels` | | `[]` | Flush channels, e.g. `['minute', 'hour']` |
| `fail_silently` | | true | Report Redis failures while recording instead of throwing |

## Upgrading from 1.x

- Nothing is persisted any more. The plugin drops:
  - the `stats_metrics` / `stats_values` migration and models;
  - the minute and hour buckets;
  - the `FlushMinuteStats` / `FlushHourStats` jobs and their schedule;
  - the period queries (`minutes()`, `hours()`, `get()`, `total()`, …).
- If you ran the 1.x migration, the plugin no longer drops those tables; drop them yourself if you don't need them.
- To keep stats, declare `channels` and call `CommonStats::flush()` from your own schedule.
- `$memory` is gone from the stat classes: every stat is in memory.
- `CommonStats::pull()` is gone: `prometheus()` scrapes, `memory()` and `query()->memory()` read.
- Config keys `schedule`, `queue`, `retention` and `redis.ttl_hours` are gone; `channels` is new.

## Development

The tests need Redis: `REDIS_HOST` (default 127.0.0.1), using database `COMMON_STATS_TEST_REDIS_DB` (default 15), which
they empty.

```bash
composer install
usr/test   # PHPUnit
usr/lint   # Pint
```
