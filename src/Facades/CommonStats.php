<?php

namespace OneOakDigital\CommonStats\Facades;

use Illuminate\Support\Facades\Facade;
use OneOakDigital\CommonStats\Services\StatsManager;

/**
 * @method static void record(string $metric, \OneOakDigital\CommonStats\Enums\EStatType $type, int|float $value, array<string, string|int|float|bool> $labels = [], bool $memory = false)
 * @method static void increment(string $metric, int $by = 1, array<string, string|int|float|bool> $labels = [], bool $memory = false)
 * @method static \OneOakDigital\CommonStats\Query\StatsQuery query(string $stat)
 * @method static list<\OneOakDigital\CommonStats\Data\MemoryStat> memory()
 * @method static list<\OneOakDigital\CommonStats\Data\MemoryStat> pull()
 * @method static string prometheus()
 *
 * @see StatsManager
 */
class CommonStats extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StatsManager::class;
    }
}
