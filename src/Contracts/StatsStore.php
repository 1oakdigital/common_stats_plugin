<?php

namespace OneOakDigital\CommonStats\Contracts;

use OneOakDigital\CommonStats\Enums\EStatType;

/**
 * Where the stats live: Redis (RedisStore), or the PHP process (ArrayStore,
 * for tests). Nothing is persisted: the application takes what changed with
 * StatsFlusher and stores it itself, if it wants.
 *
 * Fields are "{type}|{identifier}" (a count, sum, an average's sum, a min or
 * max) and "{type}|{identifier}|n" (samples).
 *
 * - totals: running totals of the count, sum and average fields and of the
 *   samples of every type; they only grow.
 * - one window per channel ("scrape", and each flush channel of
 *   config('common-stats.channels')) with the min and max values since
 *   that channel last read them.
 * - per flush channel, the totals it has already stored, so a flush takes
 *   only what changed since its previous one. Channels never take values
 *   from each other.
 */
interface StatsStore
{
    /**
     * The channel of the Prometheus scrape (and memory reads).
     */
    public const string SCRAPE = 'scrape';

    public function record(string $field, EStatType $type, string $value): void;

    /**
     * The totals, and the min / max values of the scrape window, which then
     * starts again (the values are kept as the last ones until newer come).
     *
     * @return array{totals: array<string, string>, extremes: array<string, string>}
     */
    public function scrape(): array;

    /**
     * The totals, and the min / max values of the scrape window (or the last
     * ones), changing nothing.
     *
     * @return array{totals: array<string, string>, extremes: array<string, string>}
     */
    public function peekScrape(): array;

    /**
     * Take, for a flush channel, what changed since its previous take: the
     * differences of the totals, and its window's min / max values. Only
     * the metrics with these names (all when empty).
     *
     * @param  list<string>  $names
     * @return array<string, string>
     */
    public function take(string $channel, array $names = []): array;

    /**
     * What take() would return, changing nothing.
     *
     * @param  list<string>  $names
     * @return array<string, string>
     */
    public function peek(string $channel, array $names = []): array;

    /**
     * Undo a take() whose values could not be stored.
     *
     * @param  array<string, string>  $fields
     */
    public function giveBack(string $channel, array $fields): void;
}
