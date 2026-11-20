<?php

namespace OneOakDigital\CommonStats\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OneOakDigital\CommonStats\Enums\EStatPeriod;

/**
 * A metric's value for one minute or hour: the count, sum, average, min or
 * max (by the metric's type), and how many values were recorded.
 *
 * @property int $id
 * @property int $stats_metric_id
 * @property EStatPeriod $period_eid
 * @property int $datum
 * @property float $value
 * @property int $samples
 */
class StatsValue extends Model
{
    public $timestamps = false;

    protected $table = 'stats_values';

    protected $fillable = ['stats_metric_id', 'period_eid', 'datum', 'value', 'samples'];

    /**
     * @return BelongsTo<StatsMetric, $this>
     */
    public function theMetric(): BelongsTo
    {
        return $this->belongsTo(StatsMetric::class, 'stats_metric_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_eid' => EStatPeriod::class,
            'datum' => 'integer',
            'value' => 'float',
            'samples' => 'integer',
        ];
    }
}
