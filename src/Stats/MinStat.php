<?php

namespace OneOakDigital\CommonStats\Stats;

use OneOakDigital\CommonStats\Enums\EStatType;

/**
 * Keeps the lowest recorded value.
 */
abstract class MinStat extends Stat
{
    public static function type(): EStatType
    {
        return EStatType::Min;
    }

    /**
     * @param  array<string, string|int|float|bool>  $labels
     */
    public static function record(int|float $value, array $labels = []): void
    {
        static::put($value, $labels);
    }
}
