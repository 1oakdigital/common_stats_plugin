<?php

namespace OneOakDigital\CommonStats\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use InvalidArgumentException;
use OneOakDigital\CommonStats\Enums\EStatPeriod;
use OneOakDigital\CommonStats\Facades\CommonStats;
use OneOakDigital\CommonStats\Tests\Fixtures\ApiRequests;
use OneOakDigital\CommonStats\Tests\Fixtures\CheckoutTime;
use OneOakDigital\CommonStats\Tests\Fixtures\MessagesReceived;
use OneOakDigital\CommonStats\Tests\Fixtures\QueueLag;
use OneOakDigital\CommonStats\Tests\Fixtures\Revenue;
use OneOakDigital\CommonStats\Tests\Fixtures\Signups;
use OneOakDigital\CommonStats\Tests\Fixtures\UploadSize;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

class RecordingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-20 14:37:12');
    }

    #[DataProvider('storeProvider')]
    public function test_each_type_is_aggregated_in_the_minute_and_the_hour(string $store): void
    {
        $this->useStore($store);

        Signups::increment();
        Signups::increment(by: 4);
        Revenue::add(19.99);
        Revenue::add(5);
        CheckoutTime::record(800);
        CheckoutTime::record(400);
        QueueLag::record(7);
        QueueLag::record(3);
        QueueLag::record(5);
        UploadSize::record(2.5);
        UploadSize::record(12.5);
        UploadSize::record(4);

        foreach ([[EStatPeriod::Minute, 202611201437], [EStatPeriod::Hour, 202611201400]] as [$period, $datum]) {
            $values = [];

            foreach ([Signups::class, Revenue::class, CheckoutTime::class, QueueLag::class, UploadSize::class] as $stat) {
                $point = $stat::query()->{EStatPeriod::Minute === $period ? 'minutes' : 'hours'}()->get()->sole()->points[0];
                $this->assertSame($datum, $point->datum);
                $values[$stat::make()->name()] = [round($point->value, 6), $point->samples];
            }

            $this->assertSame([
                'signup.completed' => [5.0, 2],
                'revenue.eur' => [24.99, 2],
                'checkout.ms' => [600.0, 2],
                'queue.lag_s' => [3.0, 3],
                'upload.mb' => [12.5, 3],
            ], $values);
        }
    }

    public function test_the_redis_buckets_expire_and_are_pending(): void
    {
        Signups::increment();

        $store = $this->redisStore();

        foreach ([[EStatPeriod::Minute, 202611201437], [EStatPeriod::Hour, 202611201400]] as [$period, $datum]) {
            $this->assertSame([$datum], $store->pending($period));
            $this->assertGreaterThan(47 * 3600, $this->redis()->ttl($store->bucket($period, $datum)));
        }
    }

    #[DataProvider('storeProvider')]
    public function test_a_memory_only_stat_is_not_in_the_periods(string $store): void
    {
        $this->useStore($store);

        ApiRequests::increment(['route' => 'messages.store']);

        $this->assertSame([], $this->app->make(\OneOakDigital\CommonStats\Contracts\StatsStore::class)->pending(EStatPeriod::Minute));
        $this->assertCount(1, ApiRequests::query()->memory());
    }

    public function test_labels_must_be_the_declared_ones(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MessagesReceived::increment(['site' => 'dating1']);
    }

    public function test_a_value_that_is_not_finite_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CheckoutTime::record(NAN);
    }

    public function test_metrics_can_be_recorded_by_name(): void
    {
        CommonStats::increment('signup.completed', 2);

        $this->assertSame(2.0, Signups::query()->value()?->value);
    }

    public function test_a_store_failure_is_reported_not_thrown_when_failing_silently(): void
    {
        Exceptions::fake();
        $this->breakRedis(failSilently: true);

        Signups::increment();

        Exceptions::assertReportedCount(1);
    }

    public function test_a_store_failure_is_thrown_when_not_failing_silently(): void
    {
        $this->breakRedis(failSilently: false);

        $this->expectException(Throwable::class);

        Signups::increment();
    }

    /**
     * Point the stats at a Redis that refuses connections.
     */
    private function breakRedis(bool $failSilently): void
    {
        config([
            'database.redis.broken' => ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.2],
            'common-stats.redis.connection' => 'broken',
            'common-stats.fail_silently' => $failSilently,
        ]);
        $this->useStore('phpredis');
    }
}
