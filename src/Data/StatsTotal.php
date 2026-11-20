<?php

namespace OneOakDigital\CommonStats\Data;

/**
 * A stat's value over several periods or label combinations, and how many
 * values made it.
 */
final readonly class StatsTotal
{
    public function __construct(
        public float $value,
        public int $samples,
    ) {}
}
