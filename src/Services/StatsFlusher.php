<?php

namespace OneOakDigital\CommonStats\Services;

use InvalidArgumentException;
use OneOakDigital\CommonStats\Contracts\StatsStore;
use OneOakDigital\CommonStats\Data\Aggregate;
use OneOakDigital\CommonStats\Data\Metric;
use OneOakDigital\CommonStats\Data\StatChange;
use OneOakDigital\CommonStats\Enums\EStatType;
use OneOakDigital\CommonStats\Stats\Stat;
use Throwable;

/**
 * Hands the application what changed since the previous flush of a channel
 * (config('common-stats.channels'), e.g. "minute" and "hour"), for the stats
 * it chooses, when it chooses (e.g. from its scheduler), to store wherever
 * it wants. Each channel has its own bookkeeping: flushing "minute" never
 * takes what an "hour" flush or the Prometheus scrape would get. When the
 * application's callback throws, the changes are given back for the next
 * flush.
 */
class StatsFlusher
{
    /**
     * @param  list<string>  $channels
     */
    public function __construct(private StatsStore $store, private array $channels) {}

    /**
     * The metric names of stat classes or names.
     *
     * @param  list<class-string<Stat>|string>  $stats
     * @return list<string>
     */
    public static function names(array $stats): array
    {
        return array_values(array_unique(array_map(
            fn (string $stat): string => is_subclass_of($stat, Stat::class) ? $stat::make()->name() : Metric::make($stat)->name,
            $stats,
        )));
    }

    /**
     * Take the changes of the stats (stat classes or metric names; none:
     * all) since the channel's previous flush, and pass them to $store.
     * Returns how many changes there were.
     *
     * @param  list<class-string<Stat>|string>  $stats
     * @param  callable(list<StatChange>): mixed  $store
     *
     * @throws InvalidArgumentException when the channel is not configured
     * @throws Throwable what $store throws (the changes are given back)
     */
    public function flush(string $channel, array $stats, callable $store): int
    {
        if ( ! in_array($channel, $this->channels, true)) {
            throw new InvalidArgumentException("Unknown flush channel [{$channel}]: add it to config('common-stats.channels').");
        }

        $fields = $this->store->take($channel, self::names($stats));
        $changes = $this->changes($fields);

        if ([] === $changes) {
            return 0;
        }

        try {
            $store($changes);
        } catch (Throwable $exception) {
            $this->store->giveBack($channel, $fields);

            throw $exception;
        }

        return count($changes);
    }

    /**
     * @param  array<string, string>  $fields
     * @return list<StatChange>
     */
    private function changes(array $fields): array
    {
        $changes = [];

        foreach (Aggregate::fromFields($fields) as $key => $aggregate) {
            if (0 === $aggregate->samples) {
                continue;
            }

            $changes[$key] = new StatChange(
                Metric::fromIdentifier($aggregate->identifier),
                $aggregate->type,
                $aggregate->value,
                $aggregate->samples,
                match ($aggregate->type) {
                    EStatType::Average => $aggregate->value * $aggregate->samples,
                    EStatType::Min, EStatType::Max => 0.0,
                    default => $aggregate->value,
                },
            );
        }

        ksort($changes);

        return array_values($changes);
    }
}
