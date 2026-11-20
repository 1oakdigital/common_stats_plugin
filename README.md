# Common Stats plugin

Metrics for a Laravel 13 application, declared as classes and kept in memory, in the application's own Redis. Uses:
- serve them to Prometheus (`CommonStats::prometheus()`);
- read them in the application;
- flush the ones it wants into its own database (`stats_metrics`, `stats_values`), at the intervals it chooses.

Recording never touches the database and never calls another service.

## Installation

```bash
composer require 1oakdigital/common_stats_plugin
php artisan migrate      # stats_metrics, stats_values (only needed to flush)
```

The service provider is auto-discovered and registers the migration. It schedules nothing.

Tests without Redis can set `COMMON_STATS_STORE=array` in `phpunit.xml`. The values then stay in the PHP process,
with the same behaviour.

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
- **Min / max windows**: one per consumer, i.e. the Prometheus scrape and each flush period. Each holds the lowest or
  highest value since that consumer last read it.
- **Per flush period**: the totals that period has already stored.

So the scrape, a minute flush and an hourly flush never take values from each other. Several pods can scrape and
flush the same Redis.

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

## Flushing to the database

The application stores what it wants, when it wants, typically from its scheduler:

```php
// routes/console.php
Schedule::call(fn () => CommonStats::flush(EStatPeriod::Minute, [MessagesReceived::class]))->everyMinute();
Schedule::call(fn () => CommonStats::flush(EStatPeriod::Hour, [MessagesReceived::class, RequestDuration::class]))->hourly();
Schedule::call(fn () => CommonStats::prune(EStatPeriod::Minute, now()->subDays(7)))->daily();
```

`flush($period, $stats = [], $at = null)`:
- takes what changed since that period's previous flush, for those stats (all when none are given);
- stores it in `stats_values`, as the period holding `$at`. The default is the period before now, which suits a flush
  at the start of each period.

Merging is per type:
- **Count and Sum:** the change of the total.
- **Average:** the mean of the new values.
- **Min and Max:** the extreme since the previous flush.

A row already stored for the same period and datum is merged with by type: counts and sums are added, averages
weighted by samples, min and max compared.

If the database write fails, the values are given back to Redis for the next flush, and the exception is thrown.

Periods are `EStatPeriod::Minute`, `Hour` and `Day`. Each one only receives what it is flushed. `prune($period,
$before)` deletes stored values older than `$before`.

## Reading

```php
CommonStats::memory();                                     // list<MemoryStat>: every metric in Redis, changes nothing
MessagesReceived::query()->where('result', 'queued')->memory();

$query = MessagesReceived::query()->hours()->since(now()->subDay());   // minutes() is the default, days() too
$query->get();                                 // Collection<StatsSeries>: per label combination, with its points
$query->where('result', 'queued')->points();   // list<StatsPoint>: per hour, matching combinations together
$query->total();                               // StatsTotal: the whole range together
MessagesReceived::query()->value();            // StatsTotal of the current minute
```

`memory()` returns `MemoryStat`s:
- `value`: the running total, the running mean, or the window's min or max;
- `samples`;
- `sum`, for an average.

A query reads `stats_values`, plus what hasn't been flushed for that period yet, counted in the current period. That
unflushed part is only meaningful for periods the application flushes; for any other period it is everything since
the beginning. Combining several periods or label combinations works by type, as for merging.

## Storage

**`stats_metrics`**:
- `id`;
- `identifier`, unique: `name;key=value;…` with the labels sorted by key;
- `name`, indexed;
- `labels`, JSON;
- `type_eid`, the `EStatType`;
- `created_at`.

**`stats_values`**:

| Column | |
|---|---|
| `stats_metric_id` | the metric |
| `period_eid` | `EStatPeriod`: 10 minute, 20 hour, 30 day |
| `datum` | the start of the period as `YYYYMMDDHHMM` in `app.timezone`, e.g. `202611201437` |
| `value` | the period's count, sum, average, min or max |
| `samples` | how many values were recorded |

The migration runs on SQLite, MySQL and PostgreSQL; CI tests all three.

**Redis**, under `redis.prefix` (default `common-stats:`):

| Key | Holds |
|---|---|
| `totals` | the running totals |
| `window:{scrape,minute,hour,day}` | the min/max windows |
| `last:scrape` | the min/max values the last scrape read |
| `flushed:{minute,hour,day}` | what each period has stored |
| `metrics` | a cache of `stats_metrics` ids |

Every change is a single atomic Lua script. Nothing expires. A stat whose label values keep changing (an id, a
timestamp) grows the hashes without limit, so keep label values to a small set.

A stat's type is fixed by its first stored value. Values recorded by name with a different type are dropped when
flushed, and an `UnexpectedValueException` is reported.

## Configuration

```bash
php artisan vendor:publish --tag=common-stats-config
php artisan vendor:publish --tag=common-stats-migrations   # only to change the migration
```

| Key | Env | Default | |
|-----|-----|---------|---|
| `stats` | | `[]` | The application's stat classes |
| `store` | `COMMON_STATS_STORE` | `redis` | `redis`, or `array` for tests |
| `redis.connection` | `COMMON_STATS_REDIS_CONNECTION` | `default` | Redis connection from `config/database.php` |
| `redis.prefix` | | `common-stats:` | Key prefix |
| `fail_silently` | | true | Report Redis failures while recording instead of throwing |

## Upgrading from 1.x

- `$memory` is gone from the stat classes: every stat is in memory.
- The plugin no longer has minute or hour buckets, nor the `FlushMinuteStats` / `FlushHourStats` jobs or their
  schedule. Flush with `CommonStats::flush()` from your own schedule, and prune with `CommonStats::prune()`.
- `CommonStats::pull()` is gone: `prometheus()` scrapes, and `memory()` reads.
- Config keys `schedule`, `queue`, `retention` and `redis.ttl_hours` are gone.
- The tables are unchanged.

## Development

The tests need Redis: `REDIS_HOST` (default 127.0.0.1), using database `COMMON_STATS_TEST_REDIS_DB` (default 15), which
they empty. The database is in-memory SQLite, or `DB_CONNECTION=mysql|pgsql` with `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
`DB_USERNAME` and `DB_PASSWORD`.

```bash
composer install
usr/test   # PHPUnit
usr/lint   # Pint
```
