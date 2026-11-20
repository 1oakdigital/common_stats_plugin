<?php

namespace OneOakDigital\CommonStats\Services;

use Carbon\CarbonInterface;
use OneOakDigital\CommonStats\Data\MemoryStat;
use OneOakDigital\CommonStats\Enums\EStatPeriod;
use OneOakDigital\CommonStats\Enums\EStatType;
use OneOakDigital\CommonStats\Exporters\PrometheusExporter;
use OneOakDigital\CommonStats\Models\StatsValue;
use OneOakDigital\CommonStats\Query\StatsQuery;
use OneOakDigital\CommonStats\Stats\Stat;

/**
 * The CommonStats facade: record by metric name (stat classes record
 * themselves), read what is in memory, render Prometheus, flush to the
 * database, query, prune.
 */
class StatsManager
{
    /**
     * Stored values deleted per query by prune().
     */
    public const int PRUNE_CHUNK = 10000;

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
     * The stats (all when none given) in the Prometheus text format.
     *
     * @param  list<class-string<Stat>|string>  $stats
     */
    public function prometheus(array $stats = []): string
    {
        return $this->exporter->render($stats);
    }

    /**
     * Store what changed since the previous flush of the period (see
     * StatsFlusher::flush()). Returns how many rows were written.
     *
     * @param  list<class-string<Stat>|string>  $stats
     */
    public function flush(EStatPeriod $period, array $stats = [], ?CarbonInterface $at = null): int
    {
        return $this->flusher->flush($period, $stats, $at);
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
     * Delete the stored values of the period from before $before. Returns
     * how many were deleted.
     */
    public function prune(EStatPeriod $period, CarbonInterface $before): int
    {
        $datum = $period->datum($before);
        $total = 0;

        do {
            $deleted = StatsValue::query()
                ->where('period_eid', $period)
                ->where('datum', '<', $datum)
                ->limit(self::PRUNE_CHUNK)
                ->delete();
            $total += $deleted;
        } while (self::PRUNE_CHUNK === $deleted);

        return $total;
    }
}
