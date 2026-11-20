<?php

namespace OneOakDigital\CommonStats\Tests;

use OneOakDigital\CommonStats\Data\MemoryStat;
use OneOakDigital\CommonStats\Facades\CommonStats;
use OneOakDigital\CommonStats\Tests\Fixtures\ApiDuration;
use OneOakDigital\CommonStats\Tests\Fixtures\ApiPeak;
use OneOakDigital\CommonStats\Tests\Fixtures\ApiRequests;
use OneOakDigital\CommonStats\Tests\Fixtures\Signups;
use PHPUnit\Framework\Attributes\DataProvider;

class ScrapeTest extends TestCase
{
    #[DataProvider('storeProvider')]
    public function test_totals_keep_growing_and_the_max_is_per_scrape(string $store): void
    {
        $this->useStore($store);

        ApiRequests::increment(['route' => 'a'], 2);
        ApiDuration::record(100, ['route' => 'a']);
        ApiDuration::record(300, ['route' => 'a']);
        ApiPeak::record(250);
        ApiPeak::record(900);
        $first = $this->scrape();
        $second = $this->scrape();

        ApiRequests::increment(['route' => 'a']);
        ApiDuration::record(500, ['route' => 'a']);
        ApiPeak::record(120);
        $third = $this->scrape();

        $this->assertSame([
            'api.duration_ms;route=a' => [200.0, 2, 400.0],
            'api.peak_ms' => [900.0, 2, 0.0],
            'api.requests;route=a' => [2.0, 1, 2.0],
        ], $first);
        // Nothing new: the same numbers (the max kept as the last one).
        $this->assertSame($first, $second);
        $this->assertSame([
            'api.duration_ms;route=a' => [300.0, 3, 900.0],
            'api.peak_ms' => [120.0, 3, 0.0],
            'api.requests;route=a' => [3.0, 2, 3.0],
        ], $third);
    }

    #[DataProvider('storeProvider')]
    public function test_reading_the_memory_changes_nothing(string $store): void
    {
        $this->useStore($store);

        ApiPeak::record(250);
        $this->scrape();
        ApiPeak::record(100);

        $this->assertSame(100.0, ApiPeak::query()->memory()->sole()->value);
        $this->assertSame(100.0, ApiPeak::query()->memory()->sole()->value);
        $this->assertSame(['api.peak_ms' => [100.0, 2, 0.0]], $this->scrape());
    }

    public function test_prometheus_text(): void
    {
        config(['common-stats.stats' => [ApiRequests::class, ApiDuration::class, ApiPeak::class]]);

        ApiRequests::increment(['route' => 'messages.store'], 3);
        ApiDuration::record(100, ['route' => 'messages.store']);
        ApiDuration::record(200.5, ['route' => 'messages.store']);
        ApiPeak::record(200.5);
        Signups::increment();

        $this->assertSame(<<<'TEXT'
            # HELP api_duration_ms API request duration
            # TYPE api_duration_ms summary
            api_duration_ms_sum{route="messages.store"} 300.5
            api_duration_ms_count{route="messages.store"} 2
            # HELP api_peak_ms Slowest API request
            # TYPE api_peak_ms gauge
            api_peak_ms 200.5
            # HELP api_requests_total API requests
            # TYPE api_requests_total counter
            api_requests_total{route="messages.store"} 3

            TEXT, CommonStats::prometheus([ApiRequests::class, ApiDuration::class, ApiPeak::class]));
        $this->assertStringContainsString('signup_completed_total 1', CommonStats::prometheus());
    }

    /**
     * @return array<string, array{float, int, float}>
     */
    private function scrape(): array
    {
        $summary = [];

        foreach ($this->app->make(\OneOakDigital\CommonStats\Services\StatsReader::class)->scrape() as $stat) {
            /** @var MemoryStat $stat */
            $summary[$stat->metric->identifier()] = [round($stat->value, 6), $stat->samples, round($stat->sum, 6)];
        }

        ksort($summary);

        return $summary;
    }
}
