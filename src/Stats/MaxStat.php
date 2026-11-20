<?php

namespace OneOakDigital\CommonStats\Stats;

use OneOakDigital\CommonStats\Enums\EStatType;

/**
 * Keeps the highest recorded value.
 */
abstract class MaxStat extends Stat
{
    public static function type(): EStatType
    {
        return EStatType::Max;
    }

    /**
     * @param  array<string, string|int|float|bool>  $labels
     */
    public static function record(int|float $value, array $labels = []): void
    {
        static::put($value, $labels);
    }
}
