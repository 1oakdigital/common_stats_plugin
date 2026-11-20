<?php

namespace OneOakDigital\CommonStats\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use OneOakDigital\CommonStats\Contracts\StatsStore;
use OneOakDigital\CommonStats\Data\Aggregate;
use OneOakDigital\CommonStats\Data\Metric;
use OneOakDigital\CommonStats\Enums\EStatPeriod;
use OneOakDigital\CommonStats\Models\StatsValue;
use OneOakDigital\CommonStats\Stats\Stat;
use Throwable;
use UnexpectedValueException;

/**
 * Stores what changed in the store since the previous flush of the same
 * period in stats_values, for the stats the application chooses, when it
 * chooses (e.g. every minute from its scheduler). Each period has its own
 * channel: flushing minutes never takes what an hourly flush would store.
 * A row already stored for the same period and datum is merged with
 * (Aggregate::merge()). When the database write fails, the values are given
 * back to the store for the next flush.
 */
class StatsFlusher
{
    public function __construct(private StatsStore $store, private StatsMetrics $metrics) {}

    /**
     * The metric names of stat classes or names.
     *
     * @param  list<class-string<Stat>|string>  $stats
     * @return list<string>
     */
    public static function names(array $stats): array
    {
        return array_values(array_unique(array_map(
            fn (string $stat): string => is_subclass_of($stat, Stat::class) ? $stat::make()->name() : Metric::make($stat)->name,
            $stats,
        )));
    }

    /**
     * Store the stats' changes as the period holding $at (default: the
     * period before now, for a flush at the start of each period). Returns
     * how many rows were written.
     *
     * @param  list<class-string<Stat>|string>  $stats  stat classes or metric names (none: all)
     *
     * @throws Throwable when the database write fails (nothing is lost)
     */
    public function flush(EStatPeriod $period, array $stats = [], ?CarbonInterface $at = null): int
    {
        $datum = $period->datum($at ?? $period->previous(CarbonImmutable::now()));
        $fields = $this->store->take($period->key(), self::names($stats));

        if ([] === $fields) {
            return 0;
        }

        try {
            return $this->store($period, $datum, Aggregate::fromFields($fields));
        } catch (Throwable $exception) {
            $this->store->giveBack($period->key(), $fields);

            throw $exception;
        }
    }

    /**
     * Merge the aggregates into stats_values. Returns how many rows were
     * written.
     *
     * @param  array<string, Aggregate>  $aggregates
     */
    private function store(EStatPeriod $period, int $datum, array $aggregates): int
    {
        $byMetricId = [];

        foreach ($aggregates as $aggregate) {
            if (0 === $aggregate->samples) {
                continue;
            }

            $metric = $this->metrics->resolve($aggregate->identifier, $aggregate->type);

            if ($metric['type'] !== $aggregate->type) {
                report(new UnexpectedValueException("Metric [{$aggregate->identifier}] is a {$metric['type']->name} metric; values recorded as {$aggregate->type->name} were dropped."));

                continue;
            }

            $byMetricId[$metric['id']] = $aggregate;
        }

        if ([] === $byMetricId) {
            return 0;
        }

        return DB::transaction(function () use ($period, $datum, $byMetricId): int {
            $stored = StatsValue::query()
                ->where('period_eid', $period)
                ->where('datum', $datum)
                ->whereIn('stats_metric_id', array_keys($byMetricId))
                ->get()
                ->keyBy('stats_metric_id');

            $inserts = [];

            foreach ($byMetricId as $metricId => $aggregate) {
                $existing = $stored->get($metricId);

                if (null === $existing) {
                    $inserts[] = [
                        'stats_metric_id' => $metricId,
                        'period_eid' => $period->value,
                        'datum' => $datum,
                        'value' => $aggregate->value,
                        'samples' => $aggregate->samples,
                    ];

                    continue;
                }

                $merged = new Aggregate($aggregate->identifier, $aggregate->type, $existing->value, $existing->samples)->merge($aggregate);
                $existing->forceFill(['value' => $merged->value, 'samples' => $merged->samples])->save();
            }

            foreach (array_chunk($inserts, 500) as $chunk) {
                StatsValue::query()->insert($chunk);
            }

            return count($byMetricId);
        });
    }
}
