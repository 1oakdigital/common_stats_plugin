<?php

namespace OneOakDigital\CommonStats\Services;

use InvalidArgumentException;
use OneOakDigital\CommonStats\Contracts\StatsStore;
use OneOakDigital\CommonStats\Data\Metric;
use OneOakDigital\CommonStats\Enums\EStatType;
use Throwable;

/**
 * Records metric values in the store (Redis): running totals, and min / max
 * windows. Never touches the database; the application stores what it
 * wants with StatsFlusher.
 */
class StatsRecorder
{
    public function __construct(private StatsStore $store, private bool $failSilently = true) {}

    /**
     * Count an occurrence (by $by).
     *
     * @param  array<string, string|int|float|bool>  $labels
     */
    public function increment(string $metric, int $by = 1, array $labels = []): void
    {
        $this->record($metric, EStatType::Count, $by, $labels);
    }

    /**
     * Record a value of the metric. A store failure is reported, not
     * thrown, unless common-stats.fail_silently is off.
     *
     * @param  array<string, string|int|float|bool>  $labels
     *
     * @throws InvalidArgumentException when the metric, a label or the value is invalid
     */
    public function record(string $metric, EStatType $type, int|float $value, array $labels = []): void
    {
        $identifier = Metric::make($metric, $labels)->identifier();

        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException("Invalid value for metric [{$identifier}]: it must be a finite number.");
        }

        try {
            $this->store->record($type->value . '|' . $identifier, $type, (string) $value);
        } catch (Throwable $exception) {
            if ( ! $this->failSilently) {
                throw $exception;
            }

            report($exception);
        }
    }
}
