<?php

namespace OneOakDigital\CommonStats\Query;

use Illuminate\Support\Collection;
use LogicException;
use OneOakDigital\CommonStats\Data\MemoryStat;
use OneOakDigital\CommonStats\Data\Metric;
use OneOakDigital\CommonStats\Enums\EStatType;
use OneOakDigital\CommonStats\Services\StatsReader;
use OneOakDigital\CommonStats\Stats\Stat;

/**
 * Reads a stat's label combinations as they are in memory, optionally
 * filtered by label. Immutable: every method returns a new query.
 *
 * ```php
 * MessagesReceived::query()->where('result', 'queued')->memory();
 * CommonStats::query('signup.completed')->memory();
 * ```
 */
class StatsQuery
{
    private ?string $name = null;

    private ?EStatType $type = null;

    /**
     * @var array<string, string>
     */
    private array $wheres = [];

    public function __construct(private StatsReader $reader) {}

    public function stat(Stat $stat): static
    {
        return $this->with(['name' => $stat->name(), 'type' => $stat::type()]);
    }

    /**
     * A metric by name (all its label combinations).
     */
    public function metric(string $name): static
    {
        Metric::make($name);

        return $this->with(['name' => $name]);
    }

    /**
     * Only the label combinations where the label has the value.
     */
    public function where(string $label, string|int|float|bool $value): static
    {
        $wheres = $this->wheres;
        $wheres[$label] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        return $this->with(['wheres' => $wheres]);
    }

    /**
     * The matching label combinations: running totals, min / max of the
     * scrape window. Changes nothing.
     *
     * @return Collection<int, MemoryStat>
     */
    public function memory(): Collection
    {
        if (null === $this->name) {
            throw new LogicException('Choose a stat or a metric to query.');
        }

        return collect($this->reader->memory())->filter(fn (MemoryStat $stat): bool => $this->matches($stat))->values();
    }

    private function matches(MemoryStat $stat): bool
    {
        if ($stat->metric->name !== $this->name || (null !== $this->type && $stat->type !== $this->type)) {
            return false;
        }

        foreach ($this->wheres as $label => $value) {
            if (($stat->metric->labels[$label] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function with(array $changes): static
    {
        $query = clone $this;

        foreach ($changes as $property => $value) {
            $query->{$property} = $value;
        }

        return $query;
    }
}
