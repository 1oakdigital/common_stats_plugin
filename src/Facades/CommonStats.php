<?php

namespace OneOakDigital\CommonStats\Facades;

use Illuminate\Support\Facades\Facade;
use OneOakDigital\CommonStats\StatsRecorder;

/**
 * @method static void increment(string $metric, int $by = 1)
 * @method static void sum(string $metric, int|float $value)
 * @method static void average(string $metric, int|float $value)
 * @method static void min(string $metric, int|float $value)
 * @method static void max(string $metric, int|float $value)
 * @method static void record(string $metric, \OneOakDigital\CommonStats\Enums\EStatType $type, int|float $value)
 *
 * @see StatsRecorder
 */
class CommonStats extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StatsRecorder::class;
    }
}
