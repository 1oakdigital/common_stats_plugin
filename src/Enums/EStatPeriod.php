<?php

namespace OneOakDigital\CommonStats\Enums;

use Carbon\CarbonInterface;

/**
 * The period a stored value covers. Stored in stats_values.period_eid: never
 * renumber a case.
 */
enum EStatPeriod: int
{
    case Minute = 10;

    case Hour = 20;

    /**
     * The start of the period holding $at, as YYYYMMDDHHMM in the app's
     * timezone, e.g. 202611201437 (minute) or 202611201400 (hour).
     */
    public function datum(CarbonInterface $at): int
    {
        $local = $at->copy()->setTimezone((string) config('app.timezone', 'UTC'));

        return (int) match ($this) {
            self::Minute => $local->format('YmdHi'),
            self::Hour => $local->format('YmdH') . '00',
        };
    }

    /**
     * The name used in the Redis keys.
     */
    public function key(): string
    {
        return match ($this) {
            self::Minute => 'minute',
            self::Hour => 'hour',
        };
    }
}
