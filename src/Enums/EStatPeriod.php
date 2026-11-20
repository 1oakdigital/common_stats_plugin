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

    case Day = 30;

    /**
     * The start of the period holding $at, as YYYYMMDDHHMM in the app's
     * timezone, e.g. 202611201437 (minute), 202611201400 (hour) or
     * 202611200000 (day).
     */
    public function datum(CarbonInterface $at): int
    {
        $local = $at->copy()->setTimezone((string) config('app.timezone', 'UTC'));

        return (int) match ($this) {
            self::Minute => $local->format('YmdHi'),
            self::Hour => $local->format('YmdH') . '00',
            self::Day => $local->format('Ymd') . '0000',
        };
    }

    /**
     * A moment in the period before the one holding $at: what a flush at
     * the start of a period stores.
     */
    public function previous(CarbonInterface $at): CarbonInterface
    {
        return match ($this) {
            self::Minute => $at->copy()->subMinute(),
            self::Hour => $at->copy()->subHour(),
            self::Day => $at->copy()->subDay(),
        };
    }

    /**
     * The name of the period's flush channel in the store.
     */
    public function key(): string
    {
        return match ($this) {
            self::Minute => 'minute',
            self::Hour => 'hour',
            self::Day => 'day',
        };
    }
}
