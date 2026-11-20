<?php

namespace OneOakDigital\CommonStats;

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;
use OneOakDigital\CommonStats\Enums\EStatPeriod;

/**
 * The Redis connection and key names of the stats (common-stats.redis). Key
 * names are given without Laravel's connection prefix, which the client adds.
 *
 * - {prefix}minute:{datum}, {prefix}hour:{datum}: hashes of one period's
 *   aggregates, field "{type}|{metric}" (the value) and "{type}|{metric}|n"
 *   (how many values were recorded);
 * - {prefix}pending:minute, {prefix}pending:hour: sets of the datums that
 *   have aggregates to store;
 * - {prefix}metrics: hash of metric identifier => "{stats_metrics.id}|{type}".
 */
class StatsRedis
{
    /**
     * @param  array{connection?: string|null, prefix?: string|null, ttl_hours?: int|null}  $config
     */
    public function __construct(private Factory $redis, private array $config) {}

    public function connection(): Connection
    {
        return $this->redis->connection($this->config['connection'] ?? 'default');
    }

    /**
     * The hash of one period's aggregates.
     */
    public function bucket(EStatPeriod $period, int $datum): string
    {
        return $this->prefix() . $period->key() . ':' . $datum;
    }

    /**
     * The hash of a period's aggregates while it is being stored.
     */
    public function flushing(EStatPeriod $period, int $datum): string
    {
        return $this->bucket($period, $datum) . ':flushing';
    }

    /**
     * The set of a period's datums with aggregates to store.
     */
    public function pending(EStatPeriod $period): string
    {
        return $this->prefix() . 'pending:' . $period->key();
    }

    /**
     * The hash of the metrics' ids and types.
     */
    public function metrics(): string
    {
        return $this->prefix() . 'metrics';
    }

    /**
     * How long an aggregate is kept if it is never stored, in seconds.
     */
    public function ttlSeconds(): int
    {
        return max(1, (int) ($this->config['ttl_hours'] ?? 48)) * 3600;
    }

    private function prefix(): string
    {
        return (string) ($this->config['prefix'] ?? 'common-stats:');
    }
}
