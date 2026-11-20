<?php

namespace OneOakDigital\CommonStats;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\DB;
use OneOakDigital\CommonStats\Enums\EStatPeriod;
use OneOakDigital\CommonStats\Enums\EStatType;
use OneOakDigital\CommonStats\Models\StatsMetric;
use OneOakDigital\CommonStats\Models\StatsValue;
use UnexpectedValueException;

/**
 * Stores the aggregates of the closed minutes or hours from Redis in
 * stats_values, merged with what is already stored for the same period.
 *
 * A bucket is renamed to {key}:flushing before it is read, so values
 * recorded meanwhile (a server whose clock is behind) go to a new bucket that
 * the next run stores. A leftover :flushing bucket (a run that died) is
 * stored first. A run that dies after the commit but before the :flushing
 * bucket is deleted stores that bucket twice.
 */
class StatsFlusher
{
    public function __construct(private StatsRedis $redis) {}

    /**
     * Store every closed period of the kind (before the one holding $now).
     * Returns how many values were written.
     */
    public function flush(EStatPeriod $period, ?CarbonInterface $now = null): int
    {
        $current = $period->datum($now ?? CarbonImmutable::now());
        $redis = $this->redis->connection();

        $datums = array_map(intval(...), (array) $redis->smembers($this->redis->pending($period)));
        sort($datums);

        $written = 0;

        foreach ($datums as $datum) {
            if ($datum < $current) {
                $written += $this->flushBucket($redis, $period, $datum);
            }
        }

        return $written;
    }

    private function flushBucket(Connection $redis, EStatPeriod $period, int $datum): int
    {
        $key = $this->redis->bucket($period, $datum);
        $flushing = $this->redis->flushing($period, $datum);
        $pending = $this->redis->pending($period);

        if ( ! $redis->exists($flushing)) {
            if ( ! $redis->exists($key)) {
                $redis->srem($pending, $datum);

                return 0;
            }

            $redis->rename($key, $flushing);
        }

        $written = $this->store($period, $datum, $this->aggregates((array) $redis->hgetall($flushing)));

        $redis->del($flushing);
        $redis->srem($pending, $datum);

        // Recorded after the rename: stored by the next run.
        if ($redis->exists($key)) {
            $redis->sadd($pending, $datum);
        }

        return $written;
    }

    /**
     * The hash's fields as [type value, metric] => [value, samples].
     *
     * @param  array<string, string>  $fields
     * @return array<string, array{type: EStatType, metric: string, value: float, samples: int}>
     */
    private function aggregates(array $fields): array
    {
        $aggregates = [];

        foreach ($fields as $field => $value) {
            $isSamples = str_ends_with($field, '|n');
            [$type, $metric] = explode('|', $isSamples ? mb_substr($field, 0, -2) : $field, 2) + [1 => ''];
            $type = EStatType::tryFrom((int) $type);

            if (null === $type || '' === $metric) {
                continue;
            }

            $id = $type->value . '|' . $metric;
            $aggregates[$id] ??= ['type' => $type, 'metric' => $metric, 'value' => 0.0, 'samples' => 0];

            if ($isSamples) {
                $aggregates[$id]['samples'] = (int) $value;
            } else {
                $aggregates[$id]['value'] = (float) $value;
            }
        }

        return $aggregates;
    }

    /**
     * Merge the aggregates into stats_values. Returns how many rows were
     * written.
     *
     * @param  array<string, array{type: EStatType, metric: string, value: float, samples: int}>  $aggregates
     */
    private function store(EStatPeriod $period, int $datum, array $aggregates): int
    {
        $byMetricId = [];

        foreach ($aggregates as $aggregate) {
            $metric = $this->metric($aggregate['metric'], $aggregate['type']);

            if (null !== $metric) {
                $byMetricId[$metric['id']] = $aggregate;
            }
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
                        'value' => $this->newValue($aggregate['type'], $aggregate['value'], $aggregate['samples']),
                        'samples' => $aggregate['samples'],
                    ];

                    continue;
                }

                $existing->forceFill([
                    'value' => $this->mergedValue($aggregate['type'], $existing->value, $existing->samples, $aggregate['value'], $aggregate['samples']),
                    'samples' => $existing->samples + $aggregate['samples'],
                ])->save();
            }

            foreach (array_chunk($inserts, 500) as $chunk) {
                StatsValue::query()->insert($chunk);
            }

            return count($byMetricId);
        });
    }

    /**
     * A period's value from its Redis aggregate (an average's aggregate is
     * the running sum).
     */
    private function newValue(EStatType $type, float $value, int $samples): float
    {
        return EStatType::Average === $type ? $value / max(1, $samples) : $value;
    }

    /**
     * The stored value merged with a later aggregate of the same period.
     */
    private function mergedValue(EStatType $type, float $stored, int $storedSamples, float $value, int $samples): float
    {
        return match ($type) {
            EStatType::Count, EStatType::Sum => $stored + $value,
            EStatType::Average => ($stored * $storedSamples + $value) / max(1, $storedSamples + $samples),
            EStatType::Min => min($stored, $value),
            EStatType::Max => max($stored, $value),
        };
    }

    /**
     * The metric's id, from Redis or stats_metrics (created on first use).
     * Null, reported, when it was recorded with another type than its own.
     *
     * @return array{id: int, type: EStatType}|null
     */
    private function metric(string $identifier, EStatType $type): ?array
    {
        $redis = $this->redis->connection();
        $cached = $redis->hget($this->redis->metrics(), $identifier);

        if (is_string($cached) && str_contains($cached, '|')) {
            [$id, $storedType] = explode('|', $cached, 2);
            $metric = ['id' => (int) $id, 'type' => EStatType::from((int) $storedType)];
        } else {
            $model = StatsMetric::query()->firstOrCreate(['identifier' => $identifier], ['type_eid' => $type]);
            $metric = ['id' => $model->id, 'type' => $model->type_eid];
            $redis->hset($this->redis->metrics(), $identifier, $metric['id'] . '|' . $metric['type']->value);
        }

        if ($metric['type'] !== $type) {
            report(new UnexpectedValueException("Metric [{$identifier}] is a {$metric['type']->name} metric; values recorded as {$type->name} were dropped."));

            return null;
        }

        return $metric;
    }
}
