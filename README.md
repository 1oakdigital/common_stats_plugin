# Common Stats plugin

Metrics for a Laravel 13 site, declared as classes. Values are recorded in the site's own Redis and then either:
- **stored**: aggregated per minute and per hour, and saved in the site's own database (`stats_metrics`,
  `stats_values`) by scheduled jobs;
- **memory-only**: kept in Redis until they are read, for example by a Prometheus endpoint.

Recording never touches the database and never calls another service. Reading is one API, whether the values are
already in the database or still in Redis.

## Installation

```bash
composer require 1oakdigital/common_stats_plugin
php artisan migrate
```

The service provider is auto-discovered. It registers the migration and the two scheduled jobs. The site needs:
- a Redis connection;
- a running scheduler and queue worker;
- a cache store with locks for `onOneServer()`, such as Redis.

Tests without Redis can set `COMMON_STATS_STORE=array` in `phpunit.xml`. The values then stay in the PHP process, but
behave the same way.

## Declaring stats

Each stat is a class that extends one of the base classes. The base class sets the stat's type. The class declares
its name, description, labels, and whether it is memory-only:

```php
namespace App\Stats;

use OneOakDigital\CommonStats\Stats\CountStat;

class MessagesReceived extends CountStat
{
    protected string $name = 'mailer.api.messages';

    protected string $description = 'Emails received by the messages API';

    /** Every value must have exactly these labels. */
    protected array $labels = ['site', 'result'];

    /** true: memory-only (Prometheus); false (default): stored per minute and hour. */
    protected bool $memory = false;
}
```

List the classes in `config/common-stats.php` under `stats`. That list is the inventory of what the application
collects, and it gives the Prometheus HELP text.

| Base class | Type (`EStatType`) | Record | Kept per period |
|---|---|---|---|
| `CountStat` | Count 10 | `::increment($labels, $by = 1)` | number of occurrences |
| `SumStat` | Sum 20 | `::add($value, $labels)` | total |
| `AverageStat` | Average 30 | `::record($value, $labels)` | mean |
| `MinStat` | Min 40 | `::record($value, $labels)` | lowest |
| `MaxStat` | Max 50 | `::record($value, $labels)` | highest |

```php
MessagesReceived::increment(['site' => 'dating1', 'result' => 'queued']);
SendDuration::record(182.4, ['provider' => 'gmail']);
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

## Reading

### Stored stats

`query()` reads what is in the database and what is still in Redis together: the current minute or hour, and closed
ones the jobs haven't stored yet.

```php
$query = MessagesReceived::query()->hours()->since(now()->subDay());   // minutes() is the default

$query->get();                                 // Collection<StatsSeries>: per label combination, with its points
$query->where('site', 'dating1')->points();    // list<StatsPoint>: per hour, matching combinations together
$query->where('result', 'queued')->total();    // StatsTotal: the whole range together
MessagesReceived::query()->value();            // StatsTotal of the current minute
CommonStats::query('signup.completed')->hours()->value(now()->subHour());
```

Combining several periods or label combinations works by type:
- counts and sums are added;
- averages are weighted by their samples;
- min and max take the lowest or highest value.

Each point and total also carries its `samples`.

### Memory-only stats

```php
ApiRequests::query()->where('route', 'messages.store')->memory();   // Collection<MemoryStat>, changes nothing
CommonStats::pull();                                                // every memory-only metric, pulled
CommonStats::prometheus();                                          // Prometheus text (pulls)
```

A pull atomically moves the values recorded since the last pull into running totals, so several pods or scrapers
never count a value twice:
- **Count and Sum:** `value` is the running total.
- **Average:** `value` is the running mean, `sum` and `samples` the running sum and count.
- **Min and Max:** `value` is the lowest or highest value between the last two pulls. It is kept until newer values
  arrive.

The totals start again from 0 only if Redis loses them.

### Prometheus

```php
Route::get('/metrics', fn () => response(CommonStats::prometheus(), 200, ['Content-Type' => 'text/plain; version=0.0.4']));
```

| Type | Exposed as |
|---|---|
| Count, Sum | counter `{name}_total` |
| Average | summary `{name}_sum` and `{name}_count` |
| Min, Max | gauge `{name}` |

In names, `.`, `:` and `-` become `_`. Every pod serves the same totals, so aggregate across pods with `max` in
PromQL, not `sum`. Don't cache the response: each scrape has to pull.

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
| `period_eid` | `EStatPeriod`: 10 minute, 20 hour |
| `datum` | the start of the period as `YYYYMMDDHHMM` in `app.timezone`, e.g. `202611201437` (minute) or `202611201400` (hour) |
| `value` | the period's count, sum, average, min or max |
| `samples` | how many values were recorded |

The migration runs on SQLite, MySQL and PostgreSQL; CI tests all three. There is one row per metric, period and datum.
Hour values are kept. Minute values older than `retention.minute_days` are deleted.

**Redis**, under `redis.prefix` (default `common-stats:`):

| Key | Holds |
|---|---|
| `minute:{datum}`, `hour:{datum}` | a period's bucket; renamed `…:flushing` while it is being stored, and expires after `ttl_hours` if never stored |
| `pending:minute`, `pending:hour` | the datums still to store |
| `memory` | memory-only values since the last pull |
| `memory:totals`, `memory:last` | the running totals, and the last min / max values |
| `metrics` | a cache of `stats_metrics` ids |

Each recorded value is a single atomic Lua script.

## Jobs

| Job | Schedule | Does |
|---|---|---|
| `FlushMinuteStats` | every minute | stores the closed minutes |
| `FlushHourStats` | hourly at :01 | stores the closed hours, then prunes old minute values |

Both use `withoutOverlapping()->onOneServer()` and run on `common-stats.queue`. To schedule them yourself, set
`COMMON_STATS_SCHEDULE=false`.

**Late values.** A bucket is renamed before it is read, so a value recorded meanwhile (for example from a server whose
clock is behind) lands in a new bucket. The next run merges it into the same row, and reads include it all along.

**Known gap.** If a job dies after storing a bucket but before dropping it from Redis, the next run stores that bucket
again. That double-counts its counts, sums and samples; min and max are unaffected.

A stat's type is fixed by its first stored value. Values recorded by name with a different type are dropped when
stored, and an `UnexpectedValueException` is reported.

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
| `redis.ttl_hours` | | 48 | Expiry of buckets that are never stored |
| `schedule` | `COMMON_STATS_SCHEDULE` | true | Schedule the jobs |
| `queue` | `COMMON_STATS_QUEUE` | null | The jobs' queue (null: default) |
| `retention.minute_days` | `COMMON_STATS_MINUTE_RETENTION_DAYS` | 7 | Days of minute values kept (0: all) |
| `fail_silently` | | true | Report Redis failures while recording instead of throwing |

## Development

The tests need Redis: `REDIS_HOST` (default 127.0.0.1), using database `COMMON_STATS_TEST_REDIS_DB` (default 15), which
they empty. The database is in-memory SQLite, or `DB_CONNECTION=mysql|pgsql` with `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
`DB_USERNAME` and `DB_PASSWORD`.

```bash
composer install
usr/test   # PHPUnit
usr/lint   # Pint
```
