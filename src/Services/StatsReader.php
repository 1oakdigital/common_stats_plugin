<?php

namespace OneOakDigital\CommonStats\Services;

use OneOakDigital\CommonStats\Contracts\StatsStore;
use OneOakDigital\CommonStats\Data\Aggregate;
use OneOakDigital\CommonStats\Data\MemoryStat;
use OneOakDigital\CommonStats\Data\Metric;
use OneOakDigital\CommonStats\Enums\EStatType;

/**
 * Reads the stats as they are in the store: running totals, and the min /
 * max values of the scrape window (memory(), scrape()). Stored values are
 * read with StatsQuery.
 */
class StatsReader
{
    public function __construct(private StatsStore $store) {}

    /**
     * Every metric, changing nothing.
     *
     * @return list<MemoryStat>
     */
    public function memory(): array
    {
        return $this->memoryStats(...$this->store->peekScrape());
    }

    /**
     * Every metric, for a Prometheus scrape: the min / max window starts
     * again (its values stay as the last ones until newer come). Totals are
     * never reset, so several pods or scrapers read the same numbers.
     *
     * @return list<MemoryStat>
     */
    public function scrape(): array
    {
        return $this->memoryStats(...$this->store->scrape());
    }

    /**
     * @param  array<string, string>  $totals
     * @param  array<string, string>  $extremes
     * @return list<MemoryStat>
     */
    private function memoryStats(array $totals, array $extremes): array
    {
        $aggregates = Aggregate::fromFields($totals);
        $extremeValues = Aggregate::fromFields($extremes);
        $stats = [];

        foreach ($aggregates as $key => $aggregate) {
            $isExtreme = in_array($aggregate->type, [EStatType::Min, EStatType::Max], true);
            $value = $isExtreme ? ($extremeValues[$key]->value ?? null) : $aggregate->value;

            if (null === $value) {
                continue;
            }

            $stats[$key] = new MemoryStat(
                Metric::fromIdentifier($aggregate->identifier),
                $aggregate->type,
                $value,
                $aggregate->samples,
                $isExtreme ? 0.0 : (EStatType::Average === $aggregate->type ? $value * $aggregate->samples : $value),
            );
        }

        ksort($stats);

        return array_values($stats);
    }
}
