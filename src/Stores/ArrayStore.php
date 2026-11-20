<?php

namespace OneOakDigital\CommonStats\Stores;

use OneOakDigital\CommonStats\Contracts\StatsStore;
use OneOakDigital\CommonStats\Enums\EStatPeriod;
use OneOakDigital\CommonStats\Enums\EStatType;

/**
 * The stats in the PHP process, with RedisStore's behaviour: for the tests
 * of the sites (common-stats.store = "array"), which need no Redis. Lost at
 * the end of the process.
 */
class ArrayStore implements StatsStore
{
    /**
     * @var array<string, array<string, string>>
     */
    private array $hashes = [];

    /**
     * @var array<string, array<int, true>>
     */
    private array $pending = [];

    /**
     * @var array<string, string>
     */
    private array $metrics = [];

    public function record(string $field, EStatType $type, string $value, int $minuteDatum, int $hourDatum, bool $memoryOnly): void
    {
        if ($memoryOnly) {
            $this->update('memory', $field, $type->redisMode(), $value);

            return;
        }

        foreach ([[EStatPeriod::Minute, $minuteDatum], [EStatPeriod::Hour, $hourDatum]] as [$period, $datum]) {
            $this->update($this->bucket($period, $datum), $field, $type->redisMode(), $value);
            $this->pending[$period->key()][$datum] = true;
        }
    }

    public function pending(EStatPeriod $period): array
    {
        return array_keys($this->pending[$period->key()] ?? []);
    }

    public function claim(EStatPeriod $period, int $datum): ?array
    {
        $key = $this->bucket($period, $datum);

        if ( ! isset($this->hashes[$key . ':flushing'])) {
            if ( ! isset($this->hashes[$key])) {
                unset($this->pending[$period->key()][$datum]);

                return null;
            }

            $this->hashes[$key . ':flushing'] = $this->hashes[$key];
            unset($this->hashes[$key]);
        }

        return $this->hashes[$key . ':flushing'];
    }

    public function release(EStatPeriod $period, int $datum): void
    {
        $key = $this->bucket($period, $datum);
        unset($this->hashes[$key . ':flushing']);

        if ( ! isset($this->hashes[$key])) {
            unset($this->pending[$period->key()][$datum]);
        }
    }

    public function peek(EStatPeriod $period, int $datum): array
    {
        $key = $this->bucket($period, $datum);

        return array_values(array_filter([$this->hashes[$key] ?? [], $this->hashes[$key . ':flushing'] ?? []]));
    }

    public function pullMemory(): array
    {
        $lastTypes = [(string) EStatType::Min->value, (string) EStatType::Max->value];

        foreach ($this->hashes['memory'] ?? [] as $field => $value) {
            $kind = explode('|', $field, 2)[0];

            if (in_array($kind, $lastTypes, true) && ! str_ends_with($field, '|n')) {
                $this->hashes['memory:last'][$field] = $value;
            } else {
                $this->hashes['memory:totals'][$field] = $this->number((float) ($this->hashes['memory:totals'][$field] ?? 0) + (float) $value);
            }
        }

        unset($this->hashes['memory']);

        return ['totals' => $this->hashes['memory:totals'] ?? [], 'last' => $this->hashes['memory:last'] ?? []];
    }

    public function peekMemory(): array
    {
        return [
            'current' => $this->hashes['memory'] ?? [],
            'totals' => $this->hashes['memory:totals'] ?? [],
            'last' => $this->hashes['memory:last'] ?? [],
        ];
    }

    public function cachedMetric(string $identifier): ?string
    {
        return $this->metrics[$identifier] ?? null;
    }

    public function cacheMetric(string $identifier, string $value): void
    {
        $this->metrics[$identifier] = $value;
    }

    private function update(string $key, string $field, string $mode, string $value): void
    {
        $current = $this->hashes[$key][$field] ?? null;

        $this->hashes[$key][$field] = match (true) {
            'add' === $mode => $this->number((float) ($current ?? 0) + (float) $value),
            null === $current,
            'min' === $mode && (float) $value < (float) $current,
            'max' === $mode && (float) $value > (float) $current => $value,
            default => $current,
        };

        $this->hashes[$key][$field . '|n'] = (string) ((int) ($this->hashes[$key][$field . '|n'] ?? 0) + 1);
    }

    private function bucket(EStatPeriod $period, int $datum): string
    {
        return $period->key() . ':' . $datum;
    }

    private function number(float $value): string
    {
        return (string) $value;
    }
}
