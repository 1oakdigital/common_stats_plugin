<?php

namespace OneOakDigital\CommonStats\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use OneOakDigital\CommonStats\Data\StatsSeries;
use OneOakDigital\CommonStats\Enums\EStatPeriod;
use OneOakDigital\CommonStats\Facades\CommonStats;
use OneOakDigital\CommonStats\Services\StatsFlusher;
use OneOakDigital\CommonStats\Tests\Fixtures\ApiRequests;
use OneOakDigital\CommonStats\Tests\Fixtures\CheckoutTime;
use OneOakDigital\CommonStats\Tests\Fixtures\MessagesReceived;
use PHPUnit\Framework\Attributes\DataProvider;

class QueryTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('storeProvider')]
    public function test_values_are_read_from_the_database_and_the_store_together(string $store): void
    {
        $this->useStore($store);
        $this->recordMessages();

        $series = MessagesReceived::query()->minutes()->between(Carbon::parse('2026-11-20 14:30'), Carbon::parse('2026-11-20 14:59'))->get();

        $this->assertSame([
            'mailer.api.messages;result=quarantined;site=dating1' => [[202611201437, 2.0]],
            'mailer.api.messages;result=queued;site=dating1' => [[202611201435, 3.0], [202611201436, 5.0], [202611201437, 1.0]],
            'mailer.api.messages;result=queued;site=dating2' => [[202611201436, 4.0]],
        ], $this->summarize($series));
    }

    public function test_labels_filter_and_totals_add_up(): void
    {
        $this->recordMessages();
        $query = MessagesReceived::query()->minutes()->since(Carbon::parse('2026-11-20 14:30'));

        $this->assertSame(15.0, $query->total()?->value);
        $this->assertSame(9.0, $query->where('result', 'queued')->where('site', 'dating1')->total()?->value);
        $this->assertSame(
            [[202611201435, 3.0], [202611201436, 9.0], [202611201437, 3.0]],
            array_map(fn ($point): array => [$point->datum, $point->value], $query->points()),
        );
        $this->assertSame(3.0, $query->value()?->value);
        $this->assertSame(15.0, MessagesReceived::query()->hours()->value()?->value);
        $this->assertSame(15.0, CommonStats::query('mailer.api.messages')->hours()->value()?->value);
        $this->assertNull($query->where('site', 'unknown')->total());
    }

    public function test_an_average_is_weighted_across_the_database_and_the_store(): void
    {
        Carbon::setTestNow('2026-11-20 14:36:10');
        CheckoutTime::record(100);
        Carbon::setTestNow('2026-11-20 14:36:50');
        $this->app->make(StatsFlusher::class)->flush(EStatPeriod::Minute, Carbon::parse('2026-11-20 14:37:00'));
        CheckoutTime::record(400);
        CheckoutTime::record(700);

        $total = CheckoutTime::query()->minutes()->value();

        $this->assertSame([400.0, 3], [$total?->value, $total?->samples]);
    }

    public function test_a_memory_only_stat_has_no_periods(): void
    {
        $this->expectException(LogicException::class);

        ApiRequests::query()->get();
    }

    /**
     * dating1 queued: 3 at 14:35 (stored), 5 at 14:36 (stored 2, then 3 more
     * in the store); dating2 queued: 4 at 14:36; dating1 quarantined: 2 and
     * dating1 queued: 1 at 14:37 (the current minute).
     */
    private function recordMessages(): void
    {
        Carbon::setTestNow('2026-11-20 14:35:20');
        MessagesReceived::increment(['site' => 'dating1', 'result' => 'queued'], 3);
        Carbon::setTestNow('2026-11-20 14:36:20');
        MessagesReceived::increment(['site' => 'dating1', 'result' => 'queued'], 2);
        $this->app->make(StatsFlusher::class)->flush(EStatPeriod::Minute, Carbon::parse('2026-11-20 14:37:00'));
        MessagesReceived::increment(['site' => 'dating1', 'result' => 'queued'], 3);
        MessagesReceived::increment(['site' => 'dating2', 'result' => 'queued'], 4);
        $this->app->make(StatsFlusher::class)->flush(EStatPeriod::Minute, Carbon::parse('2026-11-20 14:36:50'));
        Carbon::setTestNow('2026-11-20 14:37:20');
        MessagesReceived::increment(['site' => 'dating1', 'result' => 'quarantined'], 2);
        MessagesReceived::increment(['site' => 'dating1', 'result' => 'queued']);
    }

    /**
     * @param  iterable<StatsSeries>  $series
     * @return array<string, list<array{int, float}>>
     */
    private function summarize(iterable $series): array
    {
        $summary = [];

        foreach ($series as $one) {
            $summary[$one->metric->identifier()] = array_map(fn ($point): array => [$point->datum, $point->value], $one->points);
        }

        ksort($summary);

        return $summary;
    }
}
