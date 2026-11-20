<?php

namespace OneOakDigital\CommonStats\Stats;

use OneOakDigital\CommonStats\Enums\EStatType;

/**
 * Counts occurrences.
 */
abstract class CountStat extends Stat
{
    public static function type(): EStatType
    {
        return EStatType::Count;
    }

    /**
     * @param  array<string, string|int|float|bool>  $labels
     */
    public static function increment(array $labels = [], int $by = 1): void
    {
        static::put($by, $labels);
    }
}
