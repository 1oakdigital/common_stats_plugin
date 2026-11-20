<?php

namespace OneOakDigital\CommonStats\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use OneOakDigital\CommonStats\Enums\EStatPeriod;
use OneOakDigital\CommonStats\Services\StatsFlusher;

/**
 * Stores the closed minutes' aggregates. Scheduled every minute by
 * CommonStatsServiceProvider.
 */
#[Tries(3)]
class FlushMinuteStats implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue(config('common-stats.queue'));
    }

    public function handle(StatsFlusher $flusher): void
    {
        $flusher->flush(EStatPeriod::Minute);
    }
}
