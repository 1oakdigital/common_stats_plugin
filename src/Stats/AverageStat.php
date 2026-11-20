<?php

namespace OneOakDigital\CommonStats\Stats;

use OneOakDigital\CommonStats\Enums\EStatType;

/**
 * Keeps the mean of the recorded values.
 */
abstract class AverageStat extends Stat
{
    public static function type(): EStatType
    {
        return EStatType::Average;
    }

    /**
     * @param  array<string, string|int|float|bool>  $labels
     */
    public static function record(int|float $value, array $labels = []): void
    {
        static::put($value, $labels);
    }
}
