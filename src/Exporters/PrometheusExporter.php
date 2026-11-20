<?php

namespace OneOakDigital\CommonStats\Exporters;

use OneOakDigital\CommonStats\Data\MemoryStat;
use OneOakDigital\CommonStats\Enums\EStatType;
use OneOakDigital\CommonStats\Services\StatsReader;
use OneOakDigital\CommonStats\Stats\Stat;

/**
 * The memory-only stats in the Prometheus text format (version 0.0.4), for
 * a site's metrics endpoint. Every render pulls (StatsReader::pull()), so
 * values recorded since are moved into the running totals once, however
 * many pods or scrapers read:
 *
 * - Count, Sum: counter {name}_total, the running total;
 * - Average: summary {name}_sum and {name}_count, the running sum and count;
 * - Min, Max: gauge {name}, the lowest / highest value between the last two
 *   pulls (kept until newer values come).
 *
 * Names get . : - turned into _. HELP comes from the stat classes in
 * config('common-stats.stats'). Every pod serves the same totals: aggregate
 * across pods with max (not sum) in PromQL.
 */
class PrometheusExporter
{
    public function __construct(private StatsReader $reader) {}

    public function render(): string
    {
        $help = $this->help();
        $families = [];

        foreach ($this->reader->pull() as $stat) {
            $name = $this->name($stat->metric->name);
            $families[$name] ??= ['type' => $stat->type, 'help' => $help[$stat->metric->name] ?? null, 'stats' => []];

            if ($families[$name]['type'] === $stat->type) {
                $families[$name]['stats'][] = $stat;
            }
        }

        ksort($families);
        $lines = [];

        foreach ($families as $name => $family) {
            [$type, $samples] = match ($family['type']) {
                EStatType::Count, EStatType::Sum => ['counter', fn (MemoryStat $stat): array => ["{$name}_total" => $stat->value]],
                EStatType::Average => ['summary', fn (MemoryStat $stat): array => ["{$name}_sum" => $stat->sum, "{$name}_count" => $stat->samples]],
                EStatType::Min, EStatType::Max => ['gauge', fn (MemoryStat $stat): array => [$name => $stat->value]],
            };

            $base = EStatType::Count === $family['type'] || EStatType::Sum === $family['type'] ? "{$name}_total" : $name;

            if (null !== $family['help'] && '' !== $family['help']) {
                $lines[] = "# HELP {$base} " . str_replace(['\\', "\n"], ['\\\\', '\\n'], $family['help']);
            }

            $lines[] = "# TYPE {$base} {$type}";

            foreach ($family['stats'] as $stat) {
                foreach ($samples($stat) as $sample => $value) {
                    $lines[] = $sample . $this->labels($stat->metric->labels) . ' ' . $this->number($value);
                }
            }
        }

        return [] === $lines ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * Metric name => description, from the registered stat classes.
     *
     * @return array<string, string>
     */
    private function help(): array
    {
        $help = [];

        foreach ((array) config('common-stats.stats', []) as $class) {
            if (is_string($class) && is_subclass_of($class, Stat::class)) {
                $stat = $class::make();
                $help[$stat->name()] = $stat->description();
            }
        }

        return $help;
    }

    private function name(string $name): string
    {
        return (string) preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function labels(array $labels): string
    {
        if ([] === $labels) {
            return '';
        }

        $pairs = [];

        foreach ($labels as $key => $value) {
            $pairs[] = $key . '="' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value) . '"';
        }

        return '{' . implode(',', $pairs) . '}';
    }

    private function number(float|int $value): string
    {
        return is_int($value) || floor($value) === $value && abs($value) < 1e15 ? (string) (int) $value : (string) $value;
    }
}
