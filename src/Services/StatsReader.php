<?php

namespace OneOakDigital\CommonStats\Services;

use OneOakDigital\CommonStats\Contracts\StatsStore;
use OneOakDigital\CommonStats\Data\Aggregate;
use OneOakDigital\CommonStats\Data\MemoryStat;
use OneOakDigital\CommonStats\Data\Metric;
use OneOakDigital\CommonStats\Enums\EStatType;

/**
 * Reads the memory-only metrics (memory() and pull()). Periodic stats are
 * read with StatsQuery.
 */
class StatsReader
{
    public function __construct(private StatsStore $store) {}

    /**
     * The memory-only metrics, without resetting anything.
     *
     * @return list<MemoryStat>
     */
    public function memory(): array
    {
        $memory = $this->store->peekMemory();

        return $this->memoryStats($memory['totals'], $memory['last'], $memory['current']);
    }

    /**
     * The memory-only metrics, taking what was recorded since the last pull
     * into the running totals (count, sum, average) and the last values (min,
     * max), atomically: several readers never count a value twice.
     *
     * @return list<MemoryStat>
     */
    public function pull(): array
    {
        $memory = $this->store->pullMemory();

        return $this->memoryStats($memory['totals'], $memory['last']);
    }

    /**
     * @param  array<string, string>  $totals
     * @param  array<string, string>  $last
     * @param  array<string, string>  $current
     * @return list<MemoryStat>
     */
    private function memoryStats(array $totals, array $last, array $current = []): array
    {
        $aggregates = Aggregate::fromFields($totals);
        $lastValues = Aggregate::fromFields($last);

        foreach (Aggregate::fromFields($current) as $key => $aggregate) {
            if (in_array($aggregate->type, [EStatType::Min, EStatType::Max], true)) {
                $lastValues[$key] = $aggregate;
                $aggregates[$key] = new Aggregate($aggregate->identifier, $aggregate->type, 0.0, ($aggregates[$key]->samples ?? 0) + $aggregate->samples);

                continue;
            }

            $aggregates[$key] = isset($aggregates[$key]) ? $aggregates[$key]->merge($aggregate) : $aggregate;
        }

        $stats = [];

        foreach ($aggregates + $lastValues as $key => $aggregate) {
            $isExtreme = in_array($aggregate->type, [EStatType::Min, EStatType::Max], true);
            $value = $isExtreme ? ($lastValues[$key]->value ?? null) : $aggregates[$key]->value;

            if (null === $value) {
                continue;
            }

            $samples = $aggregates[$key]->samples ?? 0;

            $stats[$key] = new MemoryStat(
                Metric::fromIdentifier($aggregate->identifier),
                $aggregate->type,
                $value,
                $samples,
                EStatType::Average === $aggregate->type ? $value * $samples : ($isExtreme ? 0.0 : $value),
            );
        }

        ksort($stats);

        return array_values($stats);
    }
}
