# Common Stats plugin

Cheap metrics for a Laravel 13 site. Values are recorded in the site's own Redis, aggregated per minute and per hour.
Scheduled jobs store them in the site's own database (`stats_metrics`, `stats_values`). Recording never touches the
database and never calls another service.

```php
use OneOakDigital\CommonStats\Facades\CommonStats;

CommonStats::increment('signup.completed');   // count
CommonStats::increment('emails.sent', 5);     // count, +5
CommonStats::sum('revenue.eur', 19.99);       // total
CommonStats::average('checkout.ms', 812);     // mean
CommonStats::min('queue.lag_s', 3);           // lowest
CommonStats::max('upload.mb', 12.5);          // highest
```

## Installation

```bash
composer require 1oakdigital/common_stats_plugin
php artisan migrate
```

The service provider is auto-discovered, and it registers the migration and the two scheduled jobs. The site needs:
- a Redis connection;
- a running scheduler (`schedule:run` / `schedule:work`) and queue worker;
- a cache store with locks for `onOneServer()`, such as Redis.

## Metrics

A metric is a string identifier: 1–191 characters of `a-z 0-9 . _ : -`, starting with a letter or digit, for
example `signup.completed` or `payment.failed:paypal`. An invalid identifier, or a value that isn't a finite number,
throws `InvalidArgumentException`.

A metric's type is set by its first recorded value and stored in `stats_metrics.type_eid`
(`OneOakDigital\CommonStats\Enums\EStatType`):

| Type | Method | Stored per period |
|---|---|---|
| Count 10 | `increment($metric, $by = 1)` | Sum of the increments |
| Sum 20 | `sum($metric, $value)` | Total |
| Average 30 | `average($metric, $value)` | Mean |
| Min 40 | `min($metric, $value)` | Lowest |
| Max 50 | `max($metric, $value)` | Highest |

Values recorded with a different type than the metric's own are dropped when stored, and an
`UnexpectedValueException` is reported.

A Redis failure while recording is reported but not thrown (`common-stats.fail_silently`), so a metric never breaks
a request.

## Storage

**`stats_metrics`**: `id`, `identifier` (unique), `type_eid`, `created_at`.

**`stats_values`**:

| Column | |
|---|---|
| `stats_metric_id` | the metric |
| `period_eid` | `EStatPeriod`: 10 minute, 20 hour |
| `datum` | the start of the period as `YYYYMMDDHHMM` in `app.timezone`, e.g. `202611201437` (minute) or `202611201400` (hour) |
| `value` | the period's count, sum, average, min or max |
| `samples` | how many values were recorded in the period |

There is one row per metric, period and datum. Hour values are kept. Minute values older than
`retention.minute_days` are deleted.

```sql
-- Signups per hour, last day
select v.datum, v.value
from stats_values v join stats_metrics m on m.id = v.stats_metric_id
where m.identifier = 'signup.completed' and v.period_eid = 20 and v.datum >= 202611190000
order by v.datum;
```

### Redis

Key names below are under `common-stats.redis.prefix` (default `common-stats:`), on top of the connection's own prefix:

- `minute:{datum}` and `hour:{datum}`: hashes with the field `{type}|{metric}` (the sum, min or max; an average
  keeps the running sum) and the field `{type}|{metric}|n` (the samples). They expire after
  `redis.ttl_hours` if they are never stored.
- `pending:minute` and `pending:hour`: the datums waiting to be stored.
- `metrics`: identifier → `{id}|{type}`, a cache of `stats_metrics`.

Each recorded value is a single atomic Lua script that updates the minute and the hour.

## Jobs

| Job | Schedule | Does |
|---|---|---|
| `FlushMinuteStats` | every minute | stores the closed minutes |
| `FlushHourStats` | hourly at :01 | stores the closed hours, then prunes old minute values |

Both use `withoutOverlapping()->onOneServer()` and run on `common-stats.queue`. To schedule them yourself, set
`COMMON_STATS_SCHEDULE=false`.

A bucket is renamed before it is read:
- A value recorded meanwhile, for example from a server whose clock is behind, lands in a new bucket. The next run
  stores it, merged into the same row: counts and sums are added, averages weighted by their samples, min and max
  compared.
- If a job dies after storing a bucket but before deleting it from Redis, the next run stores that bucket again.
  That double-counts its counts, sums and samples; min and max are unaffected.

## Configuration

```bash
php artisan vendor:publish --tag=common-stats-config
php artisan vendor:publish --tag=common-stats-migrations   # only to change the migration
```

| Key | Env | Default | |
|-----|-----|---------|---|
| `redis.connection` | `COMMON_STATS_REDIS_CONNECTION` | `default` | Redis connection from `config/database.php` |
| `redis.prefix` | | `common-stats:` | Key prefix |
| `redis.ttl_hours` | | 48 | Expiry of aggregates that are never stored |
| `schedule` | `COMMON_STATS_SCHEDULE` | true | Schedule the jobs |
| `queue` | `COMMON_STATS_QUEUE` | null | The jobs' queue (null: default) |
| `retention.minute_days` | `COMMON_STATS_MINUTE_RETENTION_DAYS` | 7 | Days of minute values kept (0: all) |
| `fail_silently` | | true | Report Redis failures while recording instead of throwing |

## Development

The tests need a Redis server: `REDIS_HOST` (default 127.0.0.1), using database `COMMON_STATS_TEST_REDIS_DB`
(default 15), which they empty.

```bash
composer install
usr/test   # PHPUnit
usr/lint   # Pint
```
