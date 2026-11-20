<?php

namespace OneOakDigital\CommonStats\Data;

use OneOakDigital\CommonStats\Enums\EStatType;

/**
 * One label combination of a stat and its values per period, oldest first.
 */
final readonly class StatsSeries
{
    /**
     * @param  list<StatsPoint>  $points
     */
    public function __construct(
        public Metric $metric,
        public EStatType $type,
        public array $points,
    ) {}

    /**
     * The values over all the periods together (count / sum added, average
     * weighted by samples, lowest min, highest max), or null when empty.
     */
    public function total(): ?StatsTotal
    {
        $total = null;

        foreach ($this->points as $point) {
            $aggregate = new Aggregate($this->metric->identifier(), $this->type, $point->value, $point->samples);
            $total = null === $total ? $aggregate : $total->merge($aggregate);
        }

        return null === $total ? null : new StatsTotal($total->value, $total->samples);
    }
}
