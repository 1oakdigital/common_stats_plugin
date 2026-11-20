<?php

namespace OneOakDigital\CommonStats\Services;

use OneOakDigital\CommonStats\Contracts\StatsStore;
use OneOakDigital\CommonStats\Data\Metric;
use OneOakDigital\CommonStats\Enums\EStatType;
use OneOakDigital\CommonStats\Models\StatsMetric;

/**
 * The stats_metrics row of a metric identifier, cached in the store.
 */
class StatsMetrics
{
    public function __construct(private StatsStore $store) {}

    /**
     * The metric's id and type, created with $type on first use.
     *
     * @return array{id: int, type: EStatType}
     */
    public function resolve(string $identifier, EStatType $type): array
    {
        return $this->cached($identifier) ?? $this->remember(
            StatsMetric::query()->firstOrCreate(['identifier' => $identifier], [
                'name' => Metric::fromIdentifier($identifier)->name,
                'labels' => Metric::fromIdentifier($identifier)->labels ?: null,
                'type_eid' => $type,
            ]),
        );
    }

    /**
     * The metric's id and type, or null when it was never stored.
     *
     * @return array{id: int, type: EStatType}|null
     */
    public function find(string $identifier): ?array
    {
        if (($cached = $this->cached($identifier)) !== null) {
            return $cached;
        }

        $model = StatsMetric::query()->where('identifier', $identifier)->first();

        return null === $model ? null : $this->remember($model);
    }

    /**
     * @return array{id: int, type: EStatType}|null
     */
    private function cached(string $identifier): ?array
    {
        $cached = $this->store->cachedMetric($identifier);

        if (null === $cached || ! str_contains($cached, '|')) {
            return null;
        }

        [$id, $type] = explode('|', $cached, 2);

        return ['id' => (int) $id, 'type' => EStatType::from((int) $type)];
    }

    /**
     * @return array{id: int, type: EStatType}
     */
    private function remember(StatsMetric $model): array
    {
        $this->store->cacheMetric($model->identifier, $model->id . '|' . $model->type_eid->value);

        return ['id' => $model->id, 'type' => $model->type_eid];
    }
}
