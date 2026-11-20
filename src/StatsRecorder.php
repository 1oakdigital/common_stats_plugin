<?php

namespace OneOakDigital\CommonStats;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use OneOakDigital\CommonStats\Enums\EStatPeriod;
use OneOakDigital\CommonStats\Enums\EStatType;
use Throwable;

/**
 * Records metric values in Redis, aggregated per minute and per hour; the
 * jobs FlushMinuteStats and FlushHourStats store them in stats_values. Never
 * touches the database.
 */
class StatsRecorder
{
    /**
     * A metric identifier: lowercase letters, digits and . _ : -
     */
    public const string IDENTIFIER_PATTERN = '/^[a-z0-9][a-z0-9_.:-]{0,190}$/';

    /**
     * Adds the value to the minute and the hour aggregate in one atomic call.
     *
     * KEYS: minute hash, hour hash, minute pending set, hour pending set.
     * ARGV: field, mode (add, min or max), value, minute datum, hour datum,
     * TTL in seconds.
     */
    private const string SCRIPT = <<<'LUA'
        local field, mode, value = ARGV[1], ARGV[2], ARGV[3]
        for i = 1, 2 do
          local key = KEYS[i]
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
          redis.call('EXPIRE', key, ARGV[6])
          redis.call('SADD', KEYS[i + 2], ARGV[i + 3])
        end
        return 1
        LUA;

    public function __construct(private StatsRedis $redis, private bool $failSilently = true) {}

    /**
     * Count an occurrence (by $by).
     */
    public function increment(string $metric, int $by = 1): void
    {
        $this->record($metric, EStatType::Count, $by);
    }

    /**
     * Add the value to the metric's total.
     */
    public function sum(string $metric, int|float $value): void
    {
        $this->record($metric, EStatType::Sum, $value);
    }

    /**
     * Record a value of which the metric keeps the mean.
     */
    public function average(string $metric, int|float $value): void
    {
        $this->record($metric, EStatType::Average, $value);
    }

    /**
     * Record a value of which the metric keeps the lowest.
     */
    public function min(string $metric, int|float $value): void
    {
        $this->record($metric, EStatType::Min, $value);
    }

    /**
     * Record a value of which the metric keeps the highest.
     */
    public function max(string $metric, int|float $value): void
    {
        $this->record($metric, EStatType::Max, $value);
    }

    /**
     * Record a value of the metric in the current minute and hour. A Redis
     * failure is reported, not thrown, unless common-stats.fail_silently is
     * off.
     *
     * @throws InvalidArgumentException when the identifier or the value is invalid
     */
    public function record(string $metric, EStatType $type, int|float $value): void
    {
        if (1 !== preg_match(self::IDENTIFIER_PATTERN, $metric)) {
            throw new InvalidArgumentException("Invalid metric identifier [{$metric}]: use 1-191 of a-z 0-9 . _ : - starting with a letter or digit.");
        }

        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException("Invalid value for metric [{$metric}]: it must be a finite number.");
        }

        $now = CarbonImmutable::now();
        $minute = EStatPeriod::Minute->datum($now);
        $hour = EStatPeriod::Hour->datum($now);

        try {
            $this->redis->connection()->eval(
                self::SCRIPT,
                4,
                $this->redis->bucket(EStatPeriod::Minute, $minute),
                $this->redis->bucket(EStatPeriod::Hour, $hour),
                $this->redis->pending(EStatPeriod::Minute),
                $this->redis->pending(EStatPeriod::Hour),
                $type->value . '|' . $metric,
                $type->redisMode(),
                (string) $value,
                (string) $minute,
                (string) $hour,
                (string) $this->redis->ttlSeconds(),
            );
        } catch (Throwable $exception) {
            if ( ! $this->failSilently) {
                throw $exception;
            }

            report($exception);
        }
    }
}
