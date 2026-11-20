<?php

namespace OneOakDigital\CommonStats\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OneOakDigital\CommonStats\Enums\EStatType;

/**
 * A metric, by the identifier the site records it with, and its type.
 *
 * @property int $id
 * @property string $identifier
 * @property string $name
 * @property array<string, string>|null $labels
 * @property EStatType $type_eid
 */
class StatsMetric extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'stats_metrics';

    protected $fillable = ['identifier', 'name', 'labels', 'type_eid'];

    /**
     * @return HasMany<StatsValue, $this>
     */
    public function theValues(): HasMany
    {
        return $this->hasMany(StatsValue::class, 'stats_metric_id');
    }

    /**
     * The labels, sorted by key: JSON columns do not keep the order (MySQL
     * sorts the keys by length).
     *
     * @return Attribute<array<string, string>|null, array<string, string>|null>
     */
    protected function labels(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): ?array {
                $labels = null === $value ? null : json_decode($value, true);

                if ( ! is_array($labels)) {
                    return null;
                }

                ksort($labels);

                return $labels;
            },
            set: fn (?array $value): ?string => null === $value ? null : (string) json_encode($value),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type_eid' => EStatType::class,
        ];
    }
}
