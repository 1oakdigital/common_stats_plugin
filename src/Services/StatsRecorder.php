<?php

namespace OneOakDigital\CommonStats\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use OneOakDigital\CommonStats\Contracts\StatsStore;
use OneOakDigital\CommonStats\Data\Metric;
use OneOakDigital\CommonStats\Enums\EStatPeriod;
use OneOakDigital\CommonStats\Enums\EStatType;
use Throwable;

/**
 * Records metric values in the store (Redis), aggregated per minute and per
 * hour for FlushMinuteStats / FlushHourStats to store in stats_values, or,
 * with $memory, only in memory until they are read (StatsReader::pull(),
 * e.g. by a Prometheus endpoint). Never touches the database.
 */
class StatsRecorder
{
    public function __construct(private StatsStore $store, private bool $failSilently = true) {}

    /**
     * Count an occurrence (by $by).
     *
     * @param  array<string, string|int|float|bool>  $labels
     */
    public function increment(string $metric, int $by = 1, array $labels = [], bool $memory = false): void
    {
        $this->record($metric, EStatType::Count, $by, $labels, $memory);
    }

    /**
     * Record a value of the metric: in the current minute and hour, or, with
     * $memory, only in memory. A store failure is reported, not thrown,
     * unless common-stats.fail_silently is off.
     *
     * @param  array<string, string|int|float|bool>  $labels
     *
     * @throws InvalidArgumentException when the metric, a label or the value is invalid
     */
    public function record(string $metric, EStatType $type, int|float $value, array $labels = [], bool $memory = false): void
    {
        $identifier = Metric::make($metric, $labels)->identifier();

        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException("Invalid value for metric [{$identifier}]: it must be a finite number.");
        }

        $now = CarbonImmutable::now();

        try {
            $this->store->record(
                $type->value . '|' . $identifier,
                $type,
                (string) $value,
                EStatPeriod::Minute->datum($now),
                EStatPeriod::Hour->datum($now),
                $memory,
            );
        } catch (Throwable $exception) {
            if ( ! $this->failSilently) {
                throw $exception;
            }

            report($exception);
        }
    }
}
