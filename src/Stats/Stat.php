<?php

namespace OneOakDigital\CommonStats\Stats;

use InvalidArgumentException;
use OneOakDigital\CommonStats\Data\Metric;
use OneOakDigital\CommonStats\Enums\EStatType;
use OneOakDigital\CommonStats\Query\StatsQuery;
use OneOakDigital\CommonStats\Services\StatsRecorder;

/**
 * A metric the application collects, declared as a class: its name, labels
 * and description. Extend CountStat, SumStat, AverageStat, MinStat or MaxStat
 * (the type), and list the class in config('common-stats.stats').
 *
 * ```php
 * class MessagesReceived extends CountStat
 * {
 *     protected string $name = 'mailer.api.messages';
 *     protected string $description = 'Emails received by the messages API';
 *     protected array $labels = ['site', 'result'];
 * }
 *
 * MessagesReceived::increment(['site' => 'dating1', 'result' => 'queued']);
 * MessagesReceived::query()->where('site', 'dating1')->memory();
 * ```
 */
abstract class Stat
{
    /**
     * The metric name: a-z 0-9 . _ : - (Metric::NAME_PATTERN).
     */
    protected string $name;

    /**
     * What it measures (the Prometheus HELP text).
     */
    protected string $description = '';

    /**
     * The label keys every value must have, no more, no less.
     *
     * @var list<string>
     */
    protected array $labels = [];

    abstract public static function type(): EStatType;

    public static function make(): static
    {
        /** @phpstan-ignore new.static */
        return new static;
    }

    /**
     * A query of the stat's label combinations in memory.
     */
    public static function query(): StatsQuery
    {
        return app(StatsQuery::class)->stat(static::make());
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        return $this->labels;
    }

    /**
     * The metric of a value with these labels.
     *
     * @param  array<string, string|int|float|bool>  $labels
     *
     * @throws InvalidArgumentException when the labels are not the declared ones
     */
    public function metric(array $labels = []): Metric
    {
        $given = array_keys($labels);
        $declared = $this->labels;
        sort($given);
        sort($declared);

        if ($given !== $declared) {
            throw new InvalidArgumentException(sprintf(
                'Stat %s [%s] takes the labels [%s], got [%s].',
                static::class,
                $this->name,
                implode(', ', $declared),
                implode(', ', $given),
            ));
        }

        return Metric::make($this->name, $labels);
    }

    /**
     * Record a value with the stat's type.
     *
     * @param  array<string, string|int|float|bool>  $labels
     */
    protected static function put(int|float $value, array $labels): void
    {
        $stat = static::make();

        app(StatsRecorder::class)->record($stat->name, static::type(), $value, $stat->metric($labels)->labels);
    }
}
