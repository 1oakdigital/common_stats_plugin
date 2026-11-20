<?php

namespace OneOakDigital\CommonStats\Tests;

use Illuminate\Support\Facades\Exceptions;
use InvalidArgumentException;
use OneOakDigital\CommonStats\Data\MemoryStat;
use OneOakDigital\CommonStats\Facades\CommonStats;
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
    #[DataProvider('storeProvider')]
    public function test_each_type_is_kept_in_memory(string $store): void
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

        $this->assertSame([
            'checkout.ms' => [600.0, 2, 1200.0],
            'queue.lag_s' => [3.0, 3, 0.0],
            'revenue.eur' => [24.99, 2, 24.99],
            'signup.completed' => [5.0, 2, 5.0],
            'upload.mb' => [12.5, 3, 0.0],
        ], $this->summarize(CommonStats::memory()));
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

        $this->assertSame(2.0, Signups::query()->memory()->sole()->value);
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
     * @param  iterable<MemoryStat>  $stats
     * @return array<string, array{float, int, float}>
     */
    private function summarize(iterable $stats): array
    {
        $summary = [];

        foreach ($stats as $stat) {
            $summary[$stat->metric->identifier()] = [round($stat->value, 6), $stat->samples, round($stat->sum, 6)];
        }

        ksort($summary);

        return $summary;
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
