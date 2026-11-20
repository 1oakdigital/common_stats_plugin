<?php

namespace OneOakDigital\CommonStats\Data;

use OneOakDigital\CommonStats\Enums\EStatType;
use OneOakDigital\CommonStats\Metric;

/**
 * A memory-only metric as of a read.
 *
 * - Count, Sum: $value is the running total since the totals were last
 *   reset (Redis restart), $samples how many values made it.
 * - Average: $value is the running mean, $sum the running total, $samples
 *   the running count (a Prometheus summary's _sum and _count).
 * - Min, Max: $value is the lowest / highest value recorded between the
 *   last two pulls (kept until newer values come), $samples the running
 *   count.
 */
final readonly class MemoryStat
{
    public function __construct(
        public Metric $metric,
        public EStatType $type,
        public float $value,
        public int $samples,
        public float $sum,
    ) {}
}
