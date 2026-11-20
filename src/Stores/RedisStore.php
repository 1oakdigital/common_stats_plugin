<?php

namespace OneOakDigital\CommonStats\Stores;

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;
use OneOakDigital\CommonStats\Contracts\StatsStore;
use OneOakDigital\CommonStats\Enums\EStatPeriod;
use OneOakDigital\CommonStats\Enums\EStatType;

/**
 * The stats in Redis (common-stats.redis). Keys under the prefix (default
 * "common-stats:"), on top of the connection's own prefix:
 *
 * - totals: running totals (StatsStore);
 * - window:{channel}: min / max values since the channel last read them;
 * - last:scrape: the min / max values the last scrape read;
 * - flushed:{channel}: the totals a flush channel has already taken;
 * - metrics: metric identifier => "{stats_metrics.id}|{type}".
 *
 * Every change is one atomic script.
 */
class RedisStore implements StatsStore
{
    /**
     * KEYS: totals, then every window. ARGV: field, mode (add, min or max),
     * value.
     */
    private const string RECORD = <<<'LUA'
        local field, mode, value = ARGV[1], ARGV[2], ARGV[3]
        if mode == 'add' then
          redis.call('HINCRBYFLOAT', KEYS[1], field, value)
        else
          for i = 2, #KEYS do
            local current = redis.call('HGET', KEYS[i], field)
            if (not current)
              or (mode == 'min' and tonumber(value) < tonumber(current))
              or (mode == 'max' and tonumber(value) > tonumber(current)) then
              redis.call('HSET', KEYS[i], field, value)
            end
          end
        end
        redis.call('HINCRBY', KEYS[1], field .. '|n', 1)
        return 1
        LUA;

    /**
     * KEYS: scrape window, last:scrape, totals. Moves the window into last.
     */
    private const string SCRAPE_SCRIPT = <<<'LUA'
        local window = redis.call('HGETALL', KEYS[1])
        for i = 1, #window, 2 do
          redis.call('HSET', KEYS[2], window[i], window[i + 1])
        end
        redis.call('DEL', KEYS[1])
        return {redis.call('HGETALL', KEYS[3]), redis.call('HGETALL', KEYS[2])}
        LUA;

    /**
     * KEYS: totals, flushed:{channel}, window:{channel}. ARGV[1]: "1" to
     * take, "0" to peek; ARGV[2...]: metric names (none: all).
     */
    private const string TAKE = <<<'LUA'
        local take = ARGV[1] == '1'
        local all = #ARGV < 2
        local names = {}
        for i = 2, #ARGV do names[ARGV[i]] = true end
        local function wanted(field)
          if all then return true end
          local name = string.match(field, '^%d+|([^;|]+)')
          return name ~= nil and names[name] == true
        end
        local out = {}
        local totals = redis.call('HGETALL', KEYS[1])
        for i = 1, #totals, 2 do
          local field, value = totals[i], totals[i + 1]
          if wanted(field) then
            local difference = tonumber(value) - tonumber(redis.call('HGET', KEYS[2], field) or '0')
            if difference ~= 0 then
              table.insert(out, field)
              table.insert(out, string.format('%.17g', difference))
              if take then redis.call('HSET', KEYS[2], field, value) end
            end
          end
        end
        local window = redis.call('HGETALL', KEYS[3])
        for i = 1, #window, 2 do
          if wanted(window[i]) then
            table.insert(out, window[i])
            table.insert(out, window[i + 1])
            if take then redis.call('HDEL', KEYS[3], window[i]) end
          end
        end
        return out
        LUA;

    /**
     * KEYS: flushed:{channel}, window:{channel}. ARGV: field, value, mode
     * triples (add: a total to take again; min / max: a window value).
     */
    private const string GIVE_BACK = <<<'LUA'
        for i = 1, #ARGV, 3 do
          local field, value, mode = ARGV[i], ARGV[i + 1], ARGV[i + 2]
          if mode == 'add' then
            redis.call('HINCRBYFLOAT', KEYS[1], field, -tonumber(value))
          else
            local current = redis.call('HGET', KEYS[2], field)
            if (not current)
              or (mode == 'min' and tonumber(value) < tonumber(current))
              or (mode == 'max' and tonumber(value) > tonumber(current)) then
              redis.call('HSET', KEYS[2], field, value)
            end
          end
        end
        return 1
        LUA;

    /**
     * @param  array{connection?: string|null, prefix?: string|null}  $config
     */
    public function __construct(private Factory $redis, private array $config) {}

    /**
     * How a taken field is given back: totals ("add") or a window value
     * ("min", "max").
     */
    public static function giveBackMode(string $field): string
    {
        if (str_ends_with($field, '|n')) {
            return 'add';
        }

        return EStatType::tryFrom((int) explode('|', $field, 2)[0])?->redisMode() ?? 'add';
    }

    /**
     * The channels with a min / max window: the scrape and every period.
     *
     * @return list<string>
     */
    public static function channels(): array
    {
        return [self::SCRAPE, ...array_map(fn (EStatPeriod $period): string => $period->key(), EStatPeriod::cases())];
    }

    public function connection(): Connection
    {
        return $this->redis->connection($this->config['connection'] ?? 'default');
    }

    public function record(string $field, EStatType $type, string $value): void
    {
        $keys = [$this->key('totals')];

        if ('add' !== $type->redisMode()) {
            foreach ($this->channels() as $channel) {
                $keys[] = $this->key('window:' . $channel);
            }
        }

        $this->connection()->eval(self::RECORD, count($keys), ...$keys, ...[$field, $type->redisMode(), $value]);
    }

    public function scrape(): array
    {
        $result = (array) $this->connection()->eval(
            self::SCRAPE_SCRIPT,
            3,
            $this->key('window:' . self::SCRAPE),
            $this->key('last:' . self::SCRAPE),
            $this->key('totals'),
        );

        return ['totals' => $this->hash((array) ($result[0] ?? [])), 'extremes' => $this->hash((array) ($result[1] ?? []))];
    }

    public function peekScrape(): array
    {
        $redis = $this->connection();

        return [
            'totals' => $this->hash((array) $redis->hgetall($this->key('totals'))),
            'extremes' => array_merge(
                $this->hash((array) $redis->hgetall($this->key('last:' . self::SCRAPE))),
                $this->hash((array) $redis->hgetall($this->key('window:' . self::SCRAPE))),
            ),
        ];
    }

    public function take(string $channel, array $names = []): array
    {
        return $this->changes($channel, $names, true);
    }

    public function peek(string $channel, array $names = []): array
    {
        return $this->changes($channel, $names, false);
    }

    public function giveBack(string $channel, array $fields): void
    {
        $arguments = [];

        foreach ($fields as $field => $value) {
            $arguments[] = $field;
            $arguments[] = $value;
            $arguments[] = self::giveBackMode($field);
        }

        if ([] !== $arguments) {
            $this->connection()->eval(self::GIVE_BACK, 2, $this->key('flushed:' . $channel), $this->key('window:' . $channel), ...$arguments);
        }
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

    public function key(string $name): string
    {
        return ($this->config['prefix'] ?? 'common-stats:') . $name;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, string>
     */
    private function changes(string $channel, array $names, bool $take): array
    {
        return $this->hash((array) $this->connection()->eval(
            self::TAKE,
            3,
            $this->key('totals'),
            $this->key('flushed:' . $channel),
            $this->key('window:' . $channel),
            $take ? '1' : '0',
            ...$names,
        ));
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
