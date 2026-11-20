<?php

namespace OneOakDigital\CommonStats\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Date;
use OneOakDigital\CommonStats\Enums\EStatPeriod;
use OneOakDigital\CommonStats\Models\StatsValue;
use OneOakDigital\CommonStats\Services\StatsFlusher;

/**
 * Stores the closed hours' aggregates, then deletes the minute values older
 * than common-stats.retention.minute_days (0 = keep them). Scheduled hourly
 * by CommonStatsServiceProvider.
 */
#[Tries(3)]
class FlushHourStats implements ShouldQueue
{
    use Queueable;

    /**
     * Minute values deleted per query.
     */
    public const int PRUNE_CHUNK = 10000;

    public function __construct()
    {
        $this->onQueue(config('common-stats.queue'));
    }

    public function handle(StatsFlusher $flusher): void
    {
        $flusher->flush(EStatPeriod::Hour);

        $this->pruneMinutes();
    }

    private function pruneMinutes(): void
    {
        $days = (int) config('common-stats.retention.minute_days');

        if ($days <= 0) {
            return;
        }

        $before = EStatPeriod::Minute->datum(Date::now()->subDays($days));

        do {
            $deleted = StatsValue::query()
                ->where('period_eid', EStatPeriod::Minute)
                ->where('datum', '<', $before)
                ->limit(self::PRUNE_CHUNK)
                ->delete();
        } while (self::PRUNE_CHUNK === $deleted);
    }
}
