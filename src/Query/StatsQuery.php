<?php

namespace OneOakDigital\CommonStats\Query;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use LogicException;
use OneOakDigital\CommonStats\Contracts\StatsStore;
use OneOakDigital\CommonStats\Data\Aggregate;
use OneOakDigital\CommonStats\Data\MemoryStat;
use OneOakDigital\CommonStats\Data\Metric;
use OneOakDigital\CommonStats\Data\StatsPoint;
use OneOakDigital\CommonStats\Data\StatsSeries;
use OneOakDigital\CommonStats\Data\StatsTotal;
use OneOakDigital\CommonStats\Enums\EStatPeriod;
use OneOakDigital\CommonStats\Enums\EStatType;
use OneOakDigital\CommonStats\Facades\CommonStats;
use OneOakDigital\CommonStats\Models\StatsMetric;
use OneOakDigital\CommonStats\Models\StatsValue;
use OneOakDigital\CommonStats\Services\StatsMetrics;
use OneOakDigital\CommonStats\Services\StatsReader;
use OneOakDigital\CommonStats\Stats\Stat;

/**
 * Reads a stat's values wherever they are: stored in stats_values, or still
 * in the store (the current period, and closed ones not stored yet), merged.
 * Immutable: every method returns a new query.
 *
 * ```php
 * MessagesSent::query()->hours()->since(now()->subDay())->where('site', 'dating1')->get();  // per label combination
 * MessagesSent::query()->minutes()->since(now()->subHour())->total();                        // all together
 * CommonStats::query('signup.completed')->value();                                          // this minute
 * ```
 *
 * A memory-only stat has no periods: read it with memory() or pull().
 */
class StatsQuery
{
    private ?string $name = null;

    private ?EStatType $type = null;

    private bool $memoryOnly = false;

    private EStatPeriod $period = EStatPeriod::Minute;

    private ?CarbonInterface $from = null;

    private ?CarbonInterface $to = null;

    /**
     * @var array<string, string>
     */
    private array $wheres = [];

    public function __construct(private StatsStore $store, private StatsMetrics $metrics, private StatsReader $reader) {}

    public function stat(Stat $stat): static
    {
        return $this->with(['name' => $stat->name(), 'type' => $stat::type(), 'memoryOnly' => $stat->isMemoryOnly()]);
    }

    /**
     * A metric by name (all its label combinations).
     */
    public function metric(string $name): static
    {
        Metric::make($name);

        return $this->with(['name' => $name]);
    }

    public function minutes(): static
    {
        return $this->with(['period' => EStatPeriod::Minute]);
    }

    public function hours(): static
    {
        return $this->with(['period' => EStatPeriod::Hour]);
    }

    /**
     * From the period holding $from to the one holding $to (inclusive).
     * Without it, the current period only.
     */
    public function between(CarbonInterface $from, CarbonInterface $to): static
    {
        return $this->with(['from' => $from, 'to' => $to]);
    }

    /**
     * From the period holding $from to the current one.
     */
    public function since(CarbonInterface $from): static
    {
        return $this->between($from, CarbonImmutable::now());
    }

    /**
     * Only the label combinations where the label has the value.
     */
    public function where(string $label, string|int|float|bool $value): static
    {
        $wheres = $this->wheres;
        $wheres[$label] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        return $this->with(['wheres' => $wheres]);
    }

    /**
     * The values per label combination.
     *
     * @return Collection<int, StatsSeries>
     */
    public function get(): Collection
    {
        $this->assertPeriodic();

        $first = $this->period->datum($this->from ?? CarbonImmutable::now());
        $last = $this->period->datum($this->to ?? CarbonImmutable::now());

        /** @var array<string, array{metric: Metric, type: EStatType, points: array<int, Aggregate>}> $series */
        $series = [];

        $stored = StatsMetric::query()->where('name', $this->name)->get()
            ->filter(fn (StatsMetric $metric): bool => $this->matches(Metric::fromIdentifier($metric->identifier), $metric->type_eid))
            ->keyBy('id');

        if ($stored->isNotEmpty()) {
            StatsValue::query()
                ->whereIn('stats_metric_id', $stored->keys())
                ->where('period_eid', $this->period)
                ->whereBetween('datum', [$first, $last])
                ->get()
                ->each(function (StatsValue $value) use (&$series, $stored): void {
                    $metric = $stored->get($value->stats_metric_id);
                    $series[$metric->identifier] ??= ['metric' => Metric::fromIdentifier($metric->identifier), 'type' => $metric->type_eid, 'points' => []];
                    $series[$metric->identifier]['points'][$value->datum] = new Aggregate($metric->identifier, $metric->type_eid, $value->value, $value->samples);
                });
        }

        foreach ($this->store->pending($this->period) as $datum) {
            if ($datum < $first || $datum > $last) {
                continue;
            }

            foreach ($this->store->peek($this->period, $datum) as $fields) {
                foreach (Aggregate::fromFields($fields) as $aggregate) {
                    $metric = Metric::fromIdentifier($aggregate->identifier);
                    $type = $series[$aggregate->identifier]['type'] ?? $this->metrics->find($aggregate->identifier)['type'] ?? $aggregate->type;

                    if ($aggregate->type !== $type || ! $this->matches($metric, $type)) {
                        continue;
                    }

                    $series[$aggregate->identifier] ??= ['metric' => $metric, 'type' => $type, 'points' => []];
                    $points = &$series[$aggregate->identifier]['points'];
                    $points[$datum] = isset($points[$datum]) ? $points[$datum]->merge($aggregate) : $aggregate;
                    unset($points);
                }
            }
        }

        ksort($series);

        return collect(array_values(array_map(function (array $one): StatsSeries {
            ksort($one['points']);

            return new StatsSeries($one['metric'], $one['type'], array_values(array_map(
                fn (int $datum, Aggregate $aggregate): StatsPoint => new StatsPoint($this->period, $datum, $aggregate->value, $aggregate->samples),
                array_keys($one['points']),
                $one['points'],
            )));
        }, $series)));
    }

    /**
     * The values per period, all matching label combinations together.
     *
     * @return list<StatsPoint>
     */
    public function points(): array
    {
        /** @var array<int, Aggregate> $points */
        $points = [];

        foreach ($this->get() as $series) {
            foreach ($series->points as $point) {
                $aggregate = new Aggregate($this->name ?? '', $series->type, $point->value, $point->samples);
                $points[$point->datum] = isset($points[$point->datum]) ? $points[$point->datum]->merge($aggregate) : $aggregate;
            }
        }

        ksort($points);

        return array_values(array_map(
            fn (int $datum, Aggregate $aggregate): StatsPoint => new StatsPoint($this->period, $datum, $aggregate->value, $aggregate->samples),
            array_keys($points),
            $points,
        ));
    }

    /**
     * The value over all the periods and matching label combinations
     * together, or null when nothing was recorded.
     */
    public function total(): ?StatsTotal
    {
        $total = null;

        foreach ($this->get() as $series) {
            $seriesTotal = $series->total();

            if (null !== $seriesTotal) {
                $aggregate = new Aggregate($this->name ?? '', $series->type, $seriesTotal->value, $seriesTotal->samples);
                $total = null === $total ? $aggregate : $total->merge($aggregate);
            }
        }

        return null === $total ? null : new StatsTotal($total->value, $total->samples);
    }

    /**
     * The value of the period holding $at (default now), all matching label
     * combinations together, or null.
     */
    public function value(?CarbonInterface $at = null): ?StatsTotal
    {
        $at ??= CarbonImmutable::now();

        return $this->between($at, $at)->total();
    }

    /**
     * A memory-only stat's label combinations, without resetting anything.
     *
     * @return Collection<int, MemoryStat>
     */
    public function memory(): Collection
    {
        return $this->filterMemory($this->reader->memory());
    }

    /**
     * A memory-only stat's label combinations, pulled (StatsReader::pull()).
     *
     * @return Collection<int, MemoryStat>
     */
    public function pull(): Collection
    {
        return $this->filterMemory($this->reader->pull());
    }

    /**
     * @param  list<MemoryStat>  $stats
     * @return Collection<int, MemoryStat>
     */
    private function filterMemory(array $stats): Collection
    {
        return collect($stats)->filter(fn (MemoryStat $stat): bool => $this->matches($stat->metric, $stat->type))->values();
    }

    private function matches(Metric $metric, EStatType $type): bool
    {
        if ($metric->name !== $this->name || (null !== $this->type && $type !== $this->type)) {
            return false;
        }

        foreach ($this->wheres as $label => $value) {
            if (($metric->labels[$label] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function assertPeriodic(): void
    {
        if (null === $this->name) {
            throw new LogicException('Choose a stat or a metric to query.');
        }

        if ($this->memoryOnly) {
            throw new LogicException("Stat [{$this->name}] is memory-only: read it with memory() or pull().");
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function with(array $changes): static
    {
        $query = clone $this;

        foreach ($changes as $property => $value) {
            $query->{$property} = $value;
        }

        return $query;
    }
}
