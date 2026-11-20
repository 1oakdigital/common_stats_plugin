<?php

namespace OneOakDigital\CommonStats\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use OneOakDigital\CommonStats\Enums\EStatPeriod;
use OneOakDigital\CommonStats\Enums\EStatType;
use OneOakDigital\CommonStats\Jobs\FlushHourStats;
use OneOakDigital\CommonStats\Jobs\FlushMinuteStats;
use OneOakDigital\CommonStats\Models\StatsMetric;
use OneOakDigital\CommonStats\Models\StatsValue;

class FlushHourStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_minute_values_older_than_the_retention_are_pruned_and_hours_kept(): void
    {
        Carbon::setTestNow('2026-11-20 14:01:00');
        $this->storeValues();

        dispatch_sync(new FlushHourStats);

        $this->assertSame(
            [[10, 202611131402], [20, 202611011400]],
            StatsValue::query()->orderBy('period_eid')->get()->map(fn (StatsValue $value): array => [$value->period_eid->value, $value->datum])->all(),
        );
    }

    public function test_minute_values_are_kept_when_retention_is_off(): void
    {
        config(['common-stats.retention.minute_days' => 0]);
        Carbon::setTestNow('2026-11-20 14:01:00');
        $this->storeValues();

        dispatch_sync(new FlushHourStats);

        $this->assertSame(3, StatsValue::query()->count());
    }

    public function test_the_jobs_run_on_the_configured_queue(): void
    {
        Queue::fake();
        config(['common-stats.queue' => 'stats']);

        dispatch(new FlushMinuteStats);
        dispatch(new FlushHourStats);

        Queue::assertPushedOn('stats', FlushMinuteStats::class);
        Queue::assertPushedOn('stats', FlushHourStats::class);
    }

    /**
     * A minute value just older and one just newer than 7 days, and an old
     * hour value.
     */
    private function storeValues(): void
    {
        $metric = StatsMetric::query()->create(['identifier' => 'signup.completed', 'name' => 'signup.completed', 'type_eid' => EStatType::Count]);

        foreach ([[EStatPeriod::Minute, 202611131400], [EStatPeriod::Minute, 202611131402], [EStatPeriod::Hour, 202611011400]] as [$period, $datum]) {
            StatsValue::query()->create(['stats_metric_id' => $metric->id, 'period_eid' => $period, 'datum' => $datum, 'value' => 1, 'samples' => 1]);
        }
    }
}
