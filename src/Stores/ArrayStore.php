<?php

namespace OneOakDigital\CommonStats\Stores;

use OneOakDigital\CommonStats\Contracts\StatsStore;
use OneOakDigital\CommonStats\Enums\EStatType;

/**
 * The stats in the PHP process, with RedisStore's behaviour: for the tests
 * of the applications (common-stats.store = "array"), which need no Redis.
 * Lost at the end of the process.
 */
class ArrayStore implements StatsStore
{
    /**
     * @var array<string, float>
     */
    private array $totals = [];

    /**
     * @var array<string, array<string, float>>
     */
    private array $windows = [];

    /**
     * @var array<string, float>
     */
    private array $last = [];

    /**
     * @var array<string, array<string, float>>
     */
    private array $flushed = [];

    /**
     * @var array<string, string>
     */
    private array $metrics = [];

    public function record(string $field, EStatType $type, string $value): void
    {
        $mode = $type->redisMode();

        if ('add' === $mode) {
            $this->totals[$field] = ($this->totals[$field] ?? 0.0) + (float) $value;
        } else {
            foreach (RedisStore::channels() as $channel) {
                $this->windows[$channel][$field] = $this->extreme($mode, $this->windows[$channel][$field] ?? null, (float) $value);
            }
        }

        $this->totals[$field . '|n'] = ($this->totals[$field . '|n'] ?? 0.0) + 1;
    }

    public function scrape(): array
    {
        foreach ($this->windows[self::SCRAPE] ?? [] as $field => $value) {
            $this->last[$field] = $value;
        }

        unset($this->windows[self::SCRAPE]);

        return ['totals' => $this->strings($this->totals), 'extremes' => $this->strings($this->last)];
    }

    public function peekScrape(): array
    {
        return [
            'totals' => $this->strings($this->totals),
            'extremes' => $this->strings(array_merge($this->last, $this->windows[self::SCRAPE] ?? [])),
        ];
    }

    public function take(string $channel, array $names = []): array
    {
        $changes = $this->peek($channel, $names);

        foreach ($changes as $field => $value) {
            if (array_key_exists($field, $this->totals)) {
                $this->flushed[$channel][$field] = $this->totals[$field];
            } else {
                unset($this->windows[$channel][$field]);
            }
        }

        return $changes;
    }

    public function peek(string $channel, array $names = []): array
    {
        $changes = [];

        foreach ($this->totals as $field => $value) {
            $difference = $value - ($this->flushed[$channel][$field] ?? 0.0);

            if ($this->wanted($field, $names) && 0 !== $difference) {
                $changes[$field] = (string) $difference;
            }
        }

        foreach ($this->windows[$channel] ?? [] as $field => $value) {
            if ($this->wanted($field, $names)) {
                $changes[$field] = (string) $value;
            }
        }

        return $changes;
    }

    public function giveBack(string $channel, array $fields): void
    {
        foreach ($fields as $field => $value) {
            $mode = RedisStore::giveBackMode($field);

            if ('add' === $mode) {
                $this->flushed[$channel][$field] = ($this->flushed[$channel][$field] ?? 0.0) - (float) $value;
            } else {
                $this->windows[$channel][$field] = $this->extreme($mode, $this->windows[$channel][$field] ?? null, (float) $value);
            }
        }
    }

    public function cachedMetric(string $identifier): ?string
    {
        return $this->metrics[$identifier] ?? null;
    }

    public function cacheMetric(string $identifier, string $value): void
    {
        $this->metrics[$identifier] = $value;
    }

    private function extreme(string $mode, ?float $current, float $value): float
    {
        return match (true) {
            null === $current => $value,
            'min' === $mode => min($current, $value),
            default => max($current, $value),
        };
    }

    /**
     * @param  list<string>  $names
     */
    private function wanted(string $field, array $names): bool
    {
        if ([] === $names) {
            return true;
        }

        return 1 === preg_match('/^\d+\|([^;|]+)/', $field, $match) && in_array($match[1], $names, true);
    }

    /**
     * @param  array<string, float>  $values
     * @return array<string, string>
     */
    private function strings(array $values): array
    {
        return array_map(fn (float $value): string => (string) $value, $values);
    }
}
