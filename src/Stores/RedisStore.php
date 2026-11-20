<?php

namespace OneOakDigital\CommonStats\Stores;

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;
use OneOakDigital\CommonStats\Contracts\StatsStore;
use OneOakDigital\CommonStats\Enums\EStatPeriod;
use OneOakDigital\CommonStats\Enums\EStatType;

/**
 * The stats in Redis (common-stats.redis). Keys, under the prefix (default
 * "common-stats:"), on top of the connection's own prefix:
 *
 * - minute:{datum}, hour:{datum}: a period's bucket (expires after ttl_hours
 *   if never stored); {key}:flushing while it is being stored;
 * - pending:minute, pending:hour: the datums with a bucket to store;
 * - memory: the memory-only values since the last pull;
 * - memory:totals, memory:last: running totals and last min / max values;
 * - metrics: metric identifier => "{stats_metrics.id}|{type}".
 *
 * Every write is one atomic script.
 */
class RedisStore implements StatsStore
{
    /**
     * Adds ARGV[3] to field ARGV[1] of the hash KEYS[i] with mode ARGV[2]
     * (add, min or max), and counts the sample.
     */
    private const string UPDATE = <<<'LUA'
        local function update(key, field, mode, value)
          if mode == 'add' then
            redis.call('HINCRBYFLOAT', key, field, value)
          else
            local current = redis.call('HGET', key, field)
            if (not current)
              or (mode == 'min' and tonumber(value) < tonumber(current))
              or (mode == 'max' and tonumber(value) > tonumber(current)) then
              redis.call('HSET', key, field, value)
            end
          end
          redis.call('HINCRBY', key, field .. '|n', 1)
        end
        LUA;

    /**
     * KEYS: minute bucket, hour bucket, minute pending, hour pending.
     * ARGV: field, mode, value, minute datum, hour datum, TTL in seconds.
     */
    private const string RECORD = self::UPDATE . <<<'LUA'

        for i = 1, 2 do
          update(KEYS[i], ARGV[1], ARGV[2], ARGV[3])
          redis.call('EXPIRE', KEYS[i], ARGV[6])
          redis.call('SADD', KEYS[i + 2], ARGV[i + 3])
        end
        return 1
        LUA;

    /**
     * KEYS: memory hash. ARGV: field, mode, value.
     */
    private const string RECORD_MEMORY = self::UPDATE . <<<'LUA'

        update(KEYS[1], ARGV[1], ARGV[2], ARGV[3])
        return 1
        LUA;

    /**
     * KEYS: memory, totals, last. ARGV: the type values whose value goes to
     * "last" (min, max). Moves the memory hash and returns totals and last.
     */
    private const string PULL = <<<'LUA'
        local fields = redis.call('HGETALL', KEYS[1])
        redis.call('DEL', KEYS[1])
        local lastTypes = {}
        for i = 1, #ARGV do lastTypes[ARGV[i]] = true end
        for i = 1, #fields, 2 do
          local field, value = fields[i], fields[i + 1]
          local isSamples = string.sub(field, -2) == '|n'
          local kind = string.match(field, '^(%d+)|')
          if lastTypes[kind] and not isSamples then
            redis.call('HSET', KEYS[3], field, value)
          else
            redis.call('HINCRBYFLOAT', KEYS[2], field, value)
          end
        end
        return {redis.call('HGETALL', KEYS[2]), redis.call('HGETALL', KEYS[3])}
        LUA;

    /**
     * @param  array{connection?: string|null, prefix?: string|null, ttl_hours?: int|null}  $config
     */
    public function __construct(private Factory $redis, private array $config) {}

    public function connection(): Connection
    {
        return $this->redis->connection($this->config['connection'] ?? 'default');
    }

    public function record(string $field, EStatType $type, string $value, int $minuteDatum, int $hourDatum, bool $memoryOnly): void
    {
        if ($memoryOnly) {
            $this->connection()->eval(self::RECORD_MEMORY, 1, $this->key('memory'), $field, $type->redisMode(), $value);

            return;
        }

        $this->connection()->eval(
            self::RECORD,
            4,
            $this->bucket(EStatPeriod::Minute, $minuteDatum),
            $this->bucket(EStatPeriod::Hour, $hourDatum),
            $this->pendingKey(EStatPeriod::Minute),
            $this->pendingKey(EStatPeriod::Hour),
            $field,
            $type->redisMode(),
            $value,
            (string) $minuteDatum,
            (string) $hourDatum,
            (string) (max(1, (int) ($this->config['ttl_hours'] ?? 48)) * 3600),
        );
    }

    public function pending(EStatPeriod $period): array
    {
        return array_map(intval(...), array_values((array) $this->connection()->smembers($this->pendingKey($period))));
    }

    public function claim(EStatPeriod $period, int $datum): ?array
    {
        $redis = $this->connection();
        $key = $this->bucket($period, $datum);
        $flushing = $key . ':flushing';

        if ( ! $redis->exists($flushing)) {
            if ( ! $redis->exists($key)) {
                $redis->srem($this->pendingKey($period), $datum);

                return null;
            }

            $redis->rename($key, $flushing);
        }

        return $this->hash((array) $redis->hgetall($flushing));
    }

    public function release(EStatPeriod $period, int $datum): void
    {
        $redis = $this->connection();
        $key = $this->bucket($period, $datum);

        $redis->del($key . ':flushing');
        $redis->srem($this->pendingKey($period), $datum);

        // Recorded after the claim: stored by the next run.
        if ($redis->exists($key)) {
            $redis->sadd($this->pendingKey($period), $datum);
        }
    }

    public function peek(EStatPeriod $period, int $datum): array
    {
        $redis = $this->connection();
        $key = $this->bucket($period, $datum);

        return array_values(array_filter([
            $this->hash((array) $redis->hgetall($key)),
            $this->hash((array) $redis->hgetall($key . ':flushing')),
        ]));
    }

    public function pullMemory(): array
    {
        $result = (array) $this->connection()->eval(
            self::PULL,
            3,
            $this->key('memory'),
            $this->key('memory:totals'),
            $this->key('memory:last'),
            (string) EStatType::Min->value,
            (string) EStatType::Max->value,
        );

        return ['totals' => $this->hash((array) ($result[0] ?? [])), 'last' => $this->hash((array) ($result[1] ?? []))];
    }

    public function peekMemory(): array
    {
        $redis = $this->connection();

        return [
            'current' => $this->hash((array) $redis->hgetall($this->key('memory'))),
            'totals' => $this->hash((array) $redis->hgetall($this->key('memory:totals'))),
            'last' => $this->hash((array) $redis->hgetall($this->key('memory:last'))),
        ];
    }

    public function cachedMetric(string $identifier): ?string
    {
        $cached = $this->connection()->hget($this->key('metrics'), $identifier);

        return is_string($cached) && '' !== $cached ? $cached : null;
    }

    public function cacheMetric(string $identifier, string $value): void
    {
        $this->connection()->hset($this->key('metrics'), $identifier, $value);
    }

    /**
     * A period's bucket key (without the connection's prefix).
     */
    public function bucket(EStatPeriod $period, int $datum): string
    {
        return $this->key($period->key() . ':' . $datum);
    }

    public function pendingKey(EStatPeriod $period): string
    {
        return $this->key('pending:' . $period->key());
    }

    public function key(string $name): string
    {
        return ($this->config['prefix'] ?? 'common-stats:') . $name;
    }

    /**
     * A hash as field => value: HGETALL answers that, a script a flat list.
     *
     * @param  array<int|string, mixed>  $reply
     * @return array<string, string>
     */
    private function hash(array $reply): array
    {
        if ( ! array_is_list($reply)) {
            return array_map(strval(...), $reply);
        }

        $hash = [];

        for ($i = 0; $i + 1 < count($reply); $i += 2) {
            $hash[(string) $reply[$i]] = (string) $reply[$i + 1];
        }

        return $hash;
    }
}
