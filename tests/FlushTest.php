<?php

namespace OneOakDigital\CommonStats\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use OneOakDigital\CommonStats\Contracts\StatsStore;
use OneOakDigital\CommonStats\Enums\EStatPeriod;
use OneOakDigital\CommonStats\Enums\EStatType;
use OneOakDigital\CommonStats\Facades\CommonStats;
use OneOakDigital\CommonStats\Models\StatsMetric;
use OneOakDigital\CommonStats\Models\StatsValue;
use OneOakDigital\CommonStats\StatsFlusher;
use OneOakDigital\CommonStats\Tests\Fixtures\CheckoutTime;
use OneOakDigital\CommonStats\Tests\Fixtures\MessagesReceived;
use OneOakDigital\CommonStats\Tests\Fixtures\Signups;
use OneOakDigital\CommonStats\Tests\Fixtures\UploadSize;
use PHPUnit\Framework\Attributes\DataProvider;
use UnexpectedValueException;

class FlushTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Two batches of values recorded in the same minute and stored by two
     * flushes, and the value then stored.
     *
     * @return array<string, array{EStatType, list<int|float>, list<int|float>, float}>
     */
    public static function mergeProvider(): array
    {
        return [
            'count' => [EStatType::Count, [1, 2], [3], 6.0],
            'sum' => [EStatType::Sum, [1.5, 2], [10], 13.5],
            'average' => [EStatType::Average, [10, 20], [60], 30.0],
            'min' => [EStatType::Min, [5, 3], [4], 3.0],
            'max' => [EStatType::Max, [5, 3], [9], 9.0],
        ];
    }

    #[DataProvider('storeProvider')]
    public function test_closed_minutes_are_stored_and_the_current_one_is_left(string $store): void
    {
        $this->useStore($store);

        Carbon::setTestNow('2026-11-20 14:36:30');
        Signups::increment(by: 2);
        Carbon::setTestNow('2026-11-20 14:37:10');
        Signups::increment();

        $this->assertSame(1, $this->flusher()->flush(EStatPeriod::Minute));

        $this->assertSame([[202611201436, 2.0, 1]], $this->stored(EStatPeriod::Minute));
        $this->assertSame([202611201437], $this->app->make(StatsStore::class)->pending(EStatPeriod::Minute));
    }

    public function test_hours_are_stored_from_the_hour_buckets(): void
    {
        Carbon::setTestNow('2026-11-20 13:05:00');
        CheckoutTime::record(100);
        Carbon::setTestNow('2026-11-20 13:55:00');
        CheckoutTime::record(300);
        Carbon::setTestNow('2026-11-20 14:01:00');

        $this->flusher()->flush(EStatPeriod::Hour);

        $this->assertSame([[202611201300, 200.0, 2]], $this->stored(EStatPeriod::Hour));
        $this->assertSame([], $this->stored(EStatPeriod::Minute));
    }

    /**
     * @param  list<int|float>  $first
     * @param  list<int|float>  $second
     */
    #[DataProvider('mergeProvider')]
    public function test_a_second_flush_of_the_same_minute_is_merged(EStatType $type, array $first, array $second, float $expected): void
    {
        foreach ([$first, $second] as $batch) {
            Carbon::setTestNow('2026-11-20 14:36:30');

            foreach ($batch as $value) {
                CommonStats::record('some.metric', $type, $value);
            }

            Carbon::setTestNow('2026-11-20 14:37:30');
            $this->flusher()->flush(EStatPeriod::Minute);
        }

        $this->assertSame([[202611201436, $expected, count($first) + count($second)]], $this->stored(EStatPeriod::Minute));
    }

    public function test_metrics_are_created_once_with_their_name_labels_and_type(): void
    {
        foreach (['14:35:00', '14:36:00'] as $time) {
            Carbon::setTestNow("2026-11-20 {$time}");
            MessagesReceived::increment(['site' => 'dating1', 'result' => 'queued']);
        }

        Carbon::setTestNow('2026-11-20 14:37:00');
        $this->flusher()->flush(EStatPeriod::Minute);

        $metric = StatsMetric::query()->sole();
        $this->assertSame('mailer.api.messages;result=queued;site=dating1', $metric->identifier);
        $this->assertSame('mailer.api.messages', $metric->name);
        $this->assertSame(['result' => 'queued', 'site' => 'dating1'], $metric->labels);
        $this->assertSame(EStatType::Count, $metric->type_eid);
        $this->assertCount(2, $metric->theValues);
    }

    public function test_values_recorded_with_another_type_than_the_metrics_are_reported_and_dropped(): void
    {
        Exceptions::fake();
        Carbon::setTestNow('2026-11-20 14:35:00');
        UploadSize::record(3);
        Carbon::setTestNow('2026-11-20 14:36:00');
        $this->flusher()->flush(EStatPeriod::Minute);
        CommonStats::record('upload.mb', EStatType::Sum, 5);

        Carbon::setTestNow('2026-11-20 14:37:00');
        $this->flusher()->flush(EStatPeriod::Minute);

        $this->assertSame([[202611201435, 3.0, 1]], $this->stored(EStatPeriod::Minute));
        Exceptions::assertReported(UnexpectedValueException::class);
    }

    public function test_a_bucket_left_by_a_run_that_died_is_stored_first(): void
    {
        $store = $this->redisStore();
        Carbon::setTestNow('2026-11-20 14:36:00');
        Signups::increment(by: 2);
        // A run claimed the bucket, then died; then more was recorded.
        $store->claim(EStatPeriod::Minute, 202611201436);
        Signups::increment(by: 5);

        Carbon::setTestNow('2026-11-20 14:37:00');
        $this->flusher()->flush(EStatPeriod::Minute);
        $this->assertSame([[202611201436, 2.0, 1]], $this->stored(EStatPeriod::Minute));

        $this->flusher()->flush(EStatPeriod::Minute);
        $this->assertSame([[202611201436, 7.0, 2]], $this->stored(EStatPeriod::Minute));
        $this->assertSame([], $store->pending(EStatPeriod::Minute));
    }

    private function flusher(): StatsFlusher
    {
        return $this->app->make(StatsFlusher::class);
    }

    /**
     * The stored values of the period as [datum, value, samples].
     *
     * @return list<array{int, float, int}>
     */
    private function stored(EStatPeriod $period): array
    {
        return StatsValue::query()
            ->where('period_eid', $period)
            ->orderBy('datum')
            ->get()
            ->map(fn (StatsValue $value): array => [$value->datum, round($value->value, 6), $value->samples])
            ->all();
    }
}
