<?php

namespace OneOakDigital\CommonStats\Services;

use OneOakDigital\CommonStats\Data\MemoryStat;
use OneOakDigital\CommonStats\Data\StatChange;
use OneOakDigital\CommonStats\Enums\EStatType;
use OneOakDigital\CommonStats\Exporters\PrometheusExporter;
use OneOakDigital\CommonStats\Query\StatsQuery;
use OneOakDigital\CommonStats\Stats\Stat;

/**
 * The CommonStats facade: record by metric name (stat classes record
 * themselves), read, render Prometheus, flush to the application.
 */
class StatsManager
{
    public function __construct(
        private StatsRecorder $recorder,
        private StatsReader $reader,
        private StatsFlusher $flusher,
        private PrometheusExporter $exporter,
    ) {}

    /**
     * @param  array<string, string|int|float|bool>  $labels
     */
    public function record(string $metric, EStatType $type, int|float $value, array $labels = []): void
    {
        $this->recorder->record($metric, $type, $value, $labels);
    }

    /**
     * @param  array<string, string|int|float|bool>  $labels
     */
    public function increment(string $metric, int $by = 1, array $labels = []): void
    {
        $this->recorder->increment($metric, $by, $labels);
    }

    /**
     * Every metric as it is in memory, changing nothing.
     *
     * @return list<MemoryStat>
     */
    public function memory(): array
    {
        return $this->reader->memory();
    }

    /**
     * A query of a stat class or a metric name, in memory.
     *
     * @param  class-string<Stat>|string  $stat
     */
    public function query(string $stat): StatsQuery
    {
        return is_subclass_of($stat, Stat::class) ? $stat::query() : app(StatsQuery::class)->metric($stat);
    }

    /**
     * The stats (all when none given) in the Prometheus text format.
     *
     * @param  list<class-string<Stat>|string>  $stats
     */
    public function prometheus(array $stats = []): string
    {
        return $this->exporter->render($stats);
    }

    /**
     * Hand $store what changed since the channel's previous flush (see
     * StatsFlusher::flush()). Returns how many changes there were.
     *
     * @param  list<class-string<Stat>|string>  $stats
     * @param  callable(list<StatChange>): mixed  $store
     */
    public function flush(string $channel, array $stats, callable $store): int
    {
        return $this->flusher->flush($channel, $stats, $store);
    }
}
