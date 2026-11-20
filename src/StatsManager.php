<?php

namespace OneOakDigital\CommonStats;

use OneOakDigital\CommonStats\Data\MemoryStat;
use OneOakDigital\CommonStats\Enums\EStatType;
use OneOakDigital\CommonStats\Stats\Stat;

/**
 * The CommonStats facade: record by metric name (stat classes record
 * themselves), query, read the memory-only stats, render Prometheus.
 */
class StatsManager
{
    public function __construct(
        private StatsRecorder $recorder,
        private StatsReader $reader,
        private PrometheusExporter $exporter,
    ) {}

    /**
     * @param  array<string, string|int|float|bool>  $labels
     */
    public function record(string $metric, EStatType $type, int|float $value, array $labels = [], bool $memory = false): void
    {
        $this->recorder->record($metric, $type, $value, $labels, $memory);
    }

    /**
     * @param  array<string, string|int|float|bool>  $labels
     */
    public function increment(string $metric, int $by = 1, array $labels = [], bool $memory = false): void
    {
        $this->recorder->increment($metric, $by, $labels, $memory);
    }

    /**
     * A query of a stat class or a metric name.
     *
     * @param  class-string<Stat>|string  $stat
     */
    public function query(string $stat): StatsQuery
    {
        return is_subclass_of($stat, Stat::class) ? $stat::query() : app(StatsQuery::class)->metric($stat);
    }

    /**
     * Every memory-only metric, without resetting anything.
     *
     * @return list<MemoryStat>
     */
    public function memory(): array
    {
        return $this->reader->memory();
    }

    /**
     * Every memory-only metric, pulled into the running totals.
     *
     * @return list<MemoryStat>
     */
    public function pull(): array
    {
        return $this->reader->pull();
    }

    /**
     * The memory-only metrics in the Prometheus text format (pulls).
     */
    public function prometheus(): string
    {
        return $this->exporter->render();
    }
}
