<?php

namespace OneOakDigital\CommonStats\Tests;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use InvalidArgumentException;
use OneOakDigital\CommonStats\Enums\EStatPeriod;
use OneOakDigital\CommonStats\Facades\CommonStats;
use OneOakDigital\CommonStats\StatsRecorder;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

class StatsRecorderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-20 14:37:12');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function clientProvider(): array
    {
        return ['phpredis' => ['phpredis'], 'predis' => ['predis']];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidIdentifierProvider(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['Signup'],
            'space' => ['signup completed'],
            'pipe' => ['signup|completed'],
            'leading dot' => ['.signup'],
            'too long' => [str_repeat('a', 192)],
        ];
    }

    #[DataProvider('clientProvider')]
    public function test_each_type_is_aggregated_in_the_minute_and_the_hour(string $client): void
    {
        $this->useRedisClient($client);

        CommonStats::increment('signup.completed');
        CommonStats::increment('signup.completed', 4);
        CommonStats::sum('revenue.eur', 19.99);
        CommonStats::sum('revenue.eur', 5);
        CommonStats::average('checkout.ms', 800);
        CommonStats::average('checkout.ms', 400);
        CommonStats::min('queue.lag_s', 7);
        CommonStats::min('queue.lag_s', 3);
        CommonStats::min('queue.lag_s', 5);
        CommonStats::max('upload.mb', 2.5);
        CommonStats::max('upload.mb', 12.5);
        CommonStats::max('upload.mb', 4);

        $expected = [
            '10|signup.completed' => 5.0, '10|signup.completed|n' => 2.0,
            '20|revenue.eur' => 24.99, '20|revenue.eur|n' => 2.0,
            '30|checkout.ms' => 1200.0, '30|checkout.ms|n' => 2.0,
            '40|queue.lag_s' => 3.0, '40|queue.lag_s|n' => 3.0,
            '50|upload.mb' => 12.5, '50|upload.mb|n' => 3.0,
        ];

        foreach ([[EStatPeriod::Minute, 202611201437], [EStatPeriod::Hour, 202611201400]] as [$period, $datum]) {
            $fields = array_map(floatval(...), $this->redis()->hgetall($this->keys()->bucket($period, $datum)));
            ksort($fields);
            ksort($expected);
            $this->assertEqualsWithDelta($expected, $fields, 0.000001);
            $this->assertSame([(string) $datum], $this->redis()->smembers($this->keys()->pending($period)));
            $this->assertGreaterThan(47 * 3600, $this->redis()->ttl($this->keys()->bucket($period, $datum)));
        }
    }

    #[DataProvider('invalidIdentifierProvider')]
    public function test_an_invalid_identifier_is_refused(string $identifier): void
    {
        $this->expectException(InvalidArgumentException::class);

        CommonStats::increment($identifier);
    }

    public function test_a_value_that_is_not_finite_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CommonStats::average('checkout.ms', NAN);
    }

    public function test_a_redis_failure_is_reported_not_thrown_when_failing_silently(): void
    {
        Exceptions::fake();
        $this->breakRedis(failSilently: true);

        CommonStats::increment('signup.completed');

        Exceptions::assertReportedCount(1);
    }

    public function test_a_redis_failure_is_thrown_when_not_failing_silently(): void
    {
        $this->breakRedis(failSilently: false);

        $this->expectException(Throwable::class);

        CommonStats::increment('signup.completed');
    }

    /**
     * Point the recorder at a Redis that refuses connections.
     */
    private function breakRedis(bool $failSilently): void
    {
        config([
            'database.redis.broken' => ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.2],
            'common-stats.redis.connection' => 'broken',
            'common-stats.fail_silently' => $failSilently,
        ]);
        $this->useRedisClient('phpredis');
        $this->app->forgetInstance(StatsRecorder::class);
    }
}
