<?php

namespace OneOakDigital\CommonStats\Data;

use OneOakDigital\CommonStats\Enums\EStatPeriod;

/**
 * A metric's value for one minute or hour, from the database, Redis, or
 * both (a period not stored yet, or stored and recorded to again).
 */
final readonly class StatsPoint
{
    public function __construct(
        public EStatPeriod $period,
        public int $datum,
        public float $value,
        public int $samples,
    ) {}
}
