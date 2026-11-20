<?php

namespace OneOakDigital\CommonStats\Tests;

use InvalidArgumentException;
use OneOakDigital\CommonStats\Data\StatChange;
use OneOakDigital\CommonStats\Facades\CommonStats;
use OneOakDigital\CommonStats\Tests\Fixtures\ApiRequests;
use OneOakDigital\CommonStats\Tests\Fixtures\CheckoutTime;
use OneOakDigital\CommonStats\Tests\Fixtures\MessagesReceived;
use OneOakDigital\CommonStats\Tests\Fixtures\Revenue;
use OneOakDigital\CommonStats\Tests\Fixtures\Signups;
use OneOakDigital\CommonStats\Tests\Fixtures\UploadSize;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class FlushTest extends TestCase
{
    #[DataProvider('storeProvider')]
    public function test_a_flush_hands_over_what_changed_since_the_previous_one(string $store): void
    {
        $this->useStore($store);

        Signups::increment(by: 2);
        Revenue::add(10.5);
        CheckoutTime::record(100);
        CheckoutTime::record(300);
        UploadSize::record(4);
        UploadSize::record(9);
        $first = $this->flush('minute');

        Signups::increment();
        CheckoutTime::record(50);
        UploadSize::record(2);
        $second = $this->flush('minute');

        $this->assertSame([
            'checkout.ms' => [200.0, 2, 400.0],
            'revenue.eur' => [10.5, 1, 10.5],
            'signup.completed' => [2.0, 1, 2.0],
            'upload.mb' => [9.0, 2, 0.0],
        ], $first);
        $this->assertSame([
            'checkout.ms' => [50.0, 1, 50.0],
            'signup.completed' => [1.0, 1, 1.0],
            'upload.mb' => [2.0, 1, 0.0],
        ], $second);
        $this->assertSame([], $this->flush('minute'));
    }

    #[DataProvider('storeProvider')]
    public function test_each_channel_and_the_scrape_keep_their_own_account(string $store): void
    {
        $this->useStore($store);

        Signups::increment(by: 2);
        UploadSize::record(9);
        $this->flush('minute');
        CommonStats::prometheus();
        Signups::increment();
        UploadSize::record(2);

        $this->assertSame(['signup.completed' => [1.0, 1, 1.0], 'upload.mb' => [2.0, 1, 0.0]], $this->flush('minute'));
        $this->assertSame(['signup.completed' => [3.0, 2, 3.0], 'upload.mb' => [9.0, 2, 0.0]], $this->flush('hour'));
        $this->assertSame(3.0, Signups::query()->memory()->sole()->value);
    }

    #[DataProvider('storeProvider')]
    public function test_only_the_chosen_stats_are_flushed(string $store): void
    {
        $this->useStore($store);

        Signups::increment();
        ApiRequests::increment(['route' => 'a']);
        MessagesReceived::increment(['site' => 'x', 'result' => 'queued']);

        $this->assertSame(
            ['mailer.api.messages;result=queued;site=x', 'signup.completed'],
            array_keys($this->flush('minute', [Signups::class, 'mailer.api.messages'])),
        );
        $this->assertSame(['api.requests;route=a'], array_keys($this->flush('minute')));
    }

    #[DataProvider('storeProvider')]
    public function test_the_changes_are_given_back_when_storing_them_fails(string $store): void
    {
        $this->useStore($store);

        Signups::increment(by: 2);
        UploadSize::record(9);
        $this->flush('minute', [Signups::class]);
        Signups::increment(by: 3);
        UploadSize::record(4);

        try {
            CommonStats::flush('minute', [], fn (): never => throw new RuntimeException('database down'));
            $this->fail('The exception was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('database down', $exception->getMessage());
        }

        $this->assertSame(['signup.completed' => [3.0, 1, 3.0], 'upload.mb' => [9.0, 2, 0.0]], $this->flush('minute'));
    }

    public function test_an_unknown_channel_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CommonStats::flush('week', [], fn () => null);
    }

    /**
     * Flush the channel and summarize the changes as [value, samples, sum].
     *
     * @param  list<string>  $stats
     * @return array<string, array{float, int, float}>
     */
    private function flush(string $channel, array $stats = []): array
    {
        $summary = [];

        CommonStats::flush($channel, $stats, function (array $changes) use (&$summary): void {
            foreach ($changes as $change) {
                /** @var StatChange $change */
                $summary[$change->metric->identifier()] = [round($change->value, 6), $change->samples, round($change->sum, 6)];
            }
        });

        ksort($summary);

        return $summary;
    }
}
