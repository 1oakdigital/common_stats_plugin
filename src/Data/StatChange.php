<?php

namespace OneOakDigital\CommonStats\Data;

use OneOakDigital\CommonStats\Enums\EStatType;

/**
 * What changed in a metric since the previous flush of a channel
 * (StatsFlusher::flush()).
 *
 * - Count, Sum: $value is the change of the total.
 * - Average: $value is the mean of the new values, $sum their total.
 * - Min, Max: $value is the lowest / highest new value.
 *
 * $samples is how many values were recorded since.
 */
final readonly class StatChange
{
    public function __construct(
        public Metric $metric,
        public EStatType $type,
        public float $value,
        public int $samples,
        public float $sum,
    ) {}
}
