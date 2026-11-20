<?php

namespace OneOakDigital\CommonStats\Contracts;

use OneOakDigital\CommonStats\Enums\EStatPeriod;
use OneOakDigital\CommonStats\Enums\EStatType;

/**
 * Where values are aggregated before they are stored: Redis (RedisStore),
 * or the PHP process (ArrayStore, for tests). Hashes hold the field
 * "{type}|{identifier}" (count, sum, an average's running sum, min or max)
 * and "{type}|{identifier}|n" (samples).
 */
interface StatsStore
{
    /**
     * Add a value to the minute and hour buckets, or, memory-only, to the
     * memory hash (never stored in the database).
     */
    public function record(string $field, EStatType $type, string $value, int $minuteDatum, int $hourDatum, bool $memoryOnly): void;

    /**
     * The datums of the period with a bucket to store.
     *
     * @return list<int>
     */
    public function pending(EStatPeriod $period): array;

    /**
     * Take the bucket for storing (a bucket left by a run that died first),
     * or null when there is none. Values recorded meanwhile go to a new
     * bucket.
     *
     * @return array<string, string>|null
     */
    public function claim(EStatPeriod $period, int $datum): ?array;

    /**
     * The claimed bucket is stored: drop it.
     */
    public function release(EStatPeriod $period, int $datum): void;

    /**
     * The bucket's hashes not stored yet (the live one and a claimed one),
     * without taking them.
     *
     * @return list<array<string, string>>
     */
    public function peek(EStatPeriod $period, int $datum): array;

    /**
     * Atomically move the memory hash into the running totals (count, sum,
     * average, samples) and the last values (min, max), and return both.
     *
     * @return array{totals: array<string, string>, last: array<string, string>}
     */
    public function pullMemory(): array;

    /**
     * The memory hash, running totals and last values, without changing them.
     *
     * @return array{current: array<string, string>, totals: array<string, string>, last: array<string, string>}
     */
    public function peekMemory(): array;

    /**
     * The cached "{id}|{type}" of a metric, or null.
     */
    public function cachedMetric(string $identifier): ?string;

    public function cacheMetric(string $identifier, string $value): void;
}
