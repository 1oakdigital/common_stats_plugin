<?php

namespace OneOakDigital\CommonStats;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use OneOakDigital\CommonStats\Contracts\StatsStore;
use OneOakDigital\CommonStats\Data\Aggregate;
use OneOakDigital\CommonStats\Enums\EStatPeriod;
use OneOakDigital\CommonStats\Models\StatsValue;
use UnexpectedValueException;

/**
 * Stores the buckets of the closed minutes or hours in stats_values, merged
 * with what is already stored for the same period (Aggregate::merge()).
 *
 * A bucket is claimed (renamed) before it is read, so values recorded
 * meanwhile (a server whose clock is behind) go to a new bucket that the
 * next run stores. A bucket left by a run that died is stored first. A run
 * that dies after the commit but before the bucket is dropped stores it
 * twice.
 */
class StatsFlusher
{
    public function __construct(private StatsStore $store, private StatsMetrics $metrics) {}

    /**
     * Store every closed period of the kind (before the one holding $now).
     * Returns how many values were written.
     */
    public function flush(EStatPeriod $period, ?CarbonInterface $now = null): int
    {
        $current = $period->datum($now ?? CarbonImmutable::now());
        $datums = $this->store->pending($period);
        sort($datums);

        $written = 0;

        foreach ($datums as $datum) {
            if ($datum >= $current) {
                continue;
            }

            $fields = $this->store->claim($period, $datum);

            if (null === $fields) {
                continue;
            }

            $written += $this->store($period, $datum, Aggregate::fromFields($fields));
            $this->store->release($period, $datum);
        }

        return $written;
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
