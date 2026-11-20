<?php

namespace OneOakDigital\CommonStats\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use OneOakDigital\CommonStats\Enums\EStatPeriod;
use OneOakDigital\CommonStats\Enums\EStatType;
use OneOakDigital\CommonStats\Facades\CommonStats;
use OneOakDigital\CommonStats\Models\StatsMetric;
use OneOakDigital\CommonStats\Models\StatsValue;
use OneOakDigital\CommonStats\Tests\Fixtures\ApiRequests;
use OneOakDigital\CommonStats\Tests\Fixtures\CheckoutTime;
use OneOakDigital\CommonStats\Tests\Fixtures\MessagesReceived;
use OneOakDigital\CommonStats\Tests\Fixtures\Signups;
use OneOakDigital\CommonStats\Tests\Fixtures\UploadSize;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;
use UnexpectedValueException;

class FlushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-20 14:37:00');
    }

    /**
     * Two batches of values flushed for the same minute, and the value then
     * stored.
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
    public function test_a_flush_stores_what_changed_since_the_previous_one(string $store): void
    {
        $this->useStore($store);

        Signups::increment(by: 2);
        CheckoutTime::record(100);
        CheckoutTime::record(300);
        UploadSize::record(4);
        UploadSize::record(9);
        $this->assertSame(3, CommonStats::flush(EStatPeriod::Minute));

        Carbon::setTestNow('2026-11-20 14:38:00');
        Signups::increment();
        CheckoutTime::record(50);
        UploadSize::record(2);
        CommonStats::flush(EStatPeriod::Minute);
        Carbon::setTestNow('2026-11-20 14:39:00');

        $this->assertSame(0, CommonStats::flush(EStatPeriod::Minute));
        $this->assertSame([
            'checkout.ms' => [[202611201436, 200.0, 2], [202611201437, 50.0, 1]],
            'signup.completed' => [[202611201436, 2.0, 1], [202611201437, 1.0, 1]],
            'upload.mb' => [[202611201436, 9.0, 2], [202611201437, 2.0, 1]],
        ], $this->stored(EStatPeriod::Minute));
    }

    #[DataProvider('storeProvider')]
    public function test_each_period_and_the_scrape_have_their_own_channel(string $store): void
    {
        $this->useStore($store);

        Signups::increment(by: 2);
        UploadSize::record(9);
        CommonStats::flush(EStatPeriod::Minute);
        CommonStats::prometheus();
        Signups::increment();
        UploadSize::record(2);
        CommonStats::flush(EStatPeriod::Minute, at: now());
        CommonStats::flush(EStatPeriod::Hour, at: now());

        $this->assertSame([
            'signup.completed' => [[202611201436, 2.0, 1], [202611201437, 1.0, 1]],
            'upload.mb' => [[202611201436, 9.0, 1], [202611201437, 2.0, 1]],
        ], $this->stored(EStatPeriod::Minute));
        $this->assertSame([
            'signup.completed' => [[202611201400, 3.0, 2]],
            'upload.mb' => [[202611201400, 9.0, 2]],
        ], $this->stored(EStatPeriod::Hour));
        // The scrape still has its totals.
        $this->assertSame(3.0, Signups::query()->memory()->sole()->value);
    }

    #[DataProvider('storeProvider')]
    public function test_only_the_chosen_stats_are_flushed(string $store): void
    {
        $this->useStore($store);

        Signups::increment();
        ApiRequests::increment(['route' => 'a']);
        MessagesReceived::increment(['site' => 'x', 'result' => 'queued']);

        CommonStats::flush(EStatPeriod::Minute, [Signups::class, 'mailer.api.messages']);

        $this->assertSame(['mailer.api.messages;result=queued;site=x', 'signup.completed'], StatsMetric::query()->orderBy('identifier')->pluck('identifier')->all());

        CommonStats::flush(EStatPeriod::Minute);
        $this->assertSame(3, StatsValue::query()->count());
    }

    /**
     * @param  list<int|float>  $first
     * @param  list<int|float>  $second
     */
    #[DataProvider('mergeProvider')]
    public function test_flushing_into_an_already_stored_period_merges(EStatType $type, array $first, array $second, float $expected): void
    {
        foreach ([$first, $second] as $batch) {
            foreach ($batch as $value) {
                CommonStats::record('some.metric', $type, $value);
            }

            CommonStats::flush(EStatPeriod::Minute);
        }

        $this->assertSame(['some.metric' => [[202611201436, $expected, count($first) + count($second)]]], $this->stored(EStatPeriod::Minute));
    }

    #[DataProvider('storeProvider')]
    public function test_values_are_given_back_when_the_database_write_fails(string $store): void
    {
        $this->useStore($store);

        Signups::increment(by: 2);
        UploadSize::record(9);
        CommonStats::flush(EStatPeriod::Minute, [Signups::class]);
        Signups::increment(by: 3);

        $failed = false;

        try {
            DB::statement('alter table stats_values rename to stats_values_away');
            CommonStats::flush(EStatPeriod::Minute);
        } catch (Throwable) {
            $failed = true;
        } finally {
            DB::statement('alter table stats_values_away rename to stats_values');
        }

        CommonStats::flush(EStatPeriod::Minute);

        $this->assertTrue($failed);
        $this->assertSame([
            'signup.completed' => [[202611201436, 5.0, 2]],
            'upload.mb' => [[202611201436, 9.0, 1]],
        ], $this->stored(EStatPeriod::Minute));
    }

    public function test_metrics_are_created_once_with_their_name_labels_and_type(): void
    {
        MessagesReceived::increment(['site' => 'dating1', 'result' => 'queued']);
        CommonStats::flush(EStatPeriod::Minute);
        MessagesReceived::increment(['site' => 'dating1', 'result' => 'queued']);
        CommonStats::flush(EStatPeriod::Minute, at: now());

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
        UploadSize::record(3);
        CommonStats::flush(EStatPeriod::Minute);
        CommonStats::record('upload.mb', EStatType::Sum, 5);

        CommonStats::flush(EStatPeriod::Minute);

        $this->assertSame(['upload.mb' => [[202611201436, 3.0, 1]]], $this->stored(EStatPeriod::Minute));
        Exceptions::assertReported(UnexpectedValueException::class);
    }

    public function test_old_stored_values_are_pruned(): void
    {
        Signups::increment();
        CommonStats::flush(EStatPeriod::Minute, at: now()->subDays(8));
        Signups::increment();
        CommonStats::flush(EStatPeriod::Minute);
        Signups::increment();
        CommonStats::flush(EStatPeriod::Hour, at: now()->subDays(30));

        $this->assertSame(1, CommonStats::prune(EStatPeriod::Minute, now()->subDays(7)));
        $this->assertSame([[10, 202611201436], [20, 202610211400]], StatsValue::query()->orderBy('period_eid')->get()->map(fn (StatsValue $value): array => [$value->period_eid->value, $value->datum])->all());
    }

    /**
     * The stored values of the period per metric as [datum, value, samples].
     *
     * @return array<string, list<array{int, float, int}>>
     */
    private function stored(EStatPeriod $period): array
    {
        $stored = [];

        StatsValue::query()->with('theMetric')->where('period_eid', $period)->orderBy('datum')->get()
            ->each(function (StatsValue $value) use (&$stored): void {
                $stored[$value->theMetric->identifier][] = [$value->datum, round($value->value, 6), $value->samples];
            });

        ksort($stored);

        return $stored;
    }
}
