<?php

namespace OneOakDigital\CommonStats\Tests;

use OneOakDigital\CommonStats\Data\MemoryStat;
use OneOakDigital\CommonStats\Facades\CommonStats;
use OneOakDigital\CommonStats\Tests\Fixtures\ApiDuration;
use OneOakDigital\CommonStats\Tests\Fixtures\ApiPeak;
use OneOakDigital\CommonStats\Tests\Fixtures\ApiRequests;
use PHPUnit\Framework\Attributes\DataProvider;

class MemoryTest extends TestCase
{
    #[DataProvider('storeProvider')]
    public function test_a_pull_moves_the_values_into_running_totals_once(string $store): void
    {
        $this->useStore($store);

        ApiRequests::increment(['route' => 'a'], 2);
        ApiRequests::increment(['route' => 'b']);
        ApiDuration::record(100, ['route' => 'a']);
        ApiDuration::record(300, ['route' => 'a']);
        ApiPeak::record(250);
        ApiPeak::record(900);

        $first = $this->summarize(CommonStats::pull());
        $second = $this->summarize(CommonStats::pull());

        ApiRequests::increment(['route' => 'a']);
        ApiDuration::record(500, ['route' => 'a']);
        ApiPeak::record(120);
        $third = $this->summarize(CommonStats::pull());

        $this->assertSame([
            'api.duration_ms;route=a' => [200.0, 2, 400.0],
            'api.peak_ms' => [900.0, 2, 0.0],
            'api.requests;route=a' => [2.0, 1, 2.0],
            'api.requests;route=b' => [1.0, 1, 1.0],
        ], $first);
        // Nothing new: the same totals, nothing counted twice.
        $this->assertSame($first, $second);
        $this->assertSame([
            'api.duration_ms;route=a' => [300.0, 3, 900.0],
            'api.peak_ms' => [120.0, 3, 0.0],
            'api.requests;route=a' => [3.0, 2, 3.0],
            'api.requests;route=b' => [1.0, 1, 1.0],
        ], $third);
    }

    #[DataProvider('storeProvider')]
    public function test_reading_without_pulling_includes_the_values_since_the_last_pull(string $store): void
    {
        $this->useStore($store);

        ApiRequests::increment(['route' => 'a'], 2);
        ApiPeak::record(250);
        CommonStats::pull();
        ApiRequests::increment(['route' => 'a']);
        ApiPeak::record(100);

        $this->assertSame([
            'api.peak_ms' => [100.0, 2, 0.0],
            'api.requests;route=a' => [3.0, 2, 3.0],
        ], $this->summarize(CommonStats::memory()));
        $this->assertSame([[3.0]], ApiRequests::query()->where('route', 'a')->memory()->map(fn (MemoryStat $stat): array => [$stat->value])->all());
        // memory() changed nothing.
        $this->assertSame(['api.peak_ms' => [100.0, 2, 0.0], 'api.requests;route=a' => [3.0, 2, 3.0]], $this->summarize(CommonStats::pull()));
    }

    public function test_prometheus_text(): void
    {
        config(['common-stats.stats' => [ApiRequests::class, ApiDuration::class, ApiPeak::class]]);

        ApiRequests::increment(['route' => 'messages.store'], 3);
        ApiDuration::record(100, ['route' => 'messages.store']);
        ApiDuration::record(200.5, ['route' => 'messages.store']);
        ApiPeak::record(200.5);

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

            TEXT, CommonStats::prometheus());
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
}
