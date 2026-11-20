<?php

namespace OneOakDigital\CommonStats\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OneOakDigital\CommonStats\Enums\EStatType;

/**
 * A metric, by the identifier the site records it with, and its type.
 *
 * @property int $id
 * @property string $identifier
 * @property EStatType $type_eid
 */
class StatsMetric extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'stats_metrics';

    protected $fillable = ['identifier', 'type_eid'];

    /**
     * @return HasMany<StatsValue, $this>
     */
    public function theValues(): HasMany
    {
        return $this->hasMany(StatsValue::class, 'stats_metric_id');
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
