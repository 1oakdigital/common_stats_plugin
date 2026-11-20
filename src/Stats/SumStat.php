<?php

namespace OneOakDigital\CommonStats\Stats;

use OneOakDigital\CommonStats\Enums\EStatType;

/**
 * Totals the recorded values.
 */
abstract class SumStat extends Stat
{
    public static function type(): EStatType
    {
        return EStatType::Sum;
    }

    /**
     * @param  array<string, string|int|float|bool>  $labels
     */
    public static function add(int|float $value, array $labels = []): void
    {
        static::put($value, $labels);
    }
}
