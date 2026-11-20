<?php

namespace OneOakDigital\CommonStats\Enums;

/**
 * What a metric keeps per period. Stored in stats_metrics.type_eid: never
 * renumber a case.
 */
enum EStatType: int
{
    /**
     * How many times it happened (increments, any step).
     */
    case Count = 10;

    /**
     * The total of the recorded values.
     */
    case Sum = 20;

    /**
     * The mean of the recorded values.
     */
    case Average = 30;

    /**
     * The lowest recorded value.
     */
    case Min = 40;

    /**
     * The highest recorded value.
     */
    case Max = 50;

    /**
     * How Redis aggregates the values: "add" (count, sum, and the running
     * sum of an average), "min" or "max".
     */
    public function redisMode(): string
    {
        return match ($this) {
            self::Count, self::Sum, self::Average => 'add',
            self::Min => 'min',
            self::Max => 'max',
        };
    }
}
