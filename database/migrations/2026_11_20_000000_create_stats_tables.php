<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * stats_metrics: the metrics by identifier, with their type
     * (OneOakDigital\CommonStats\Enums\EStatType). stats_values: a metric's
     * value per minute or hour (EStatPeriod); datum is the start of the
     * period as YYYYMMDDHHMM.
     */
    public function up(): void
    {
        Schema::create('stats_metrics', function (Blueprint $table): void {
            $table->id();
            $table->string('identifier', 191)->unique();
            $table->smallInteger('type_eid');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('stats_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stats_metric_id')->constrained('stats_metrics')->cascadeOnDelete();
            $table->smallInteger('period_eid');
            $table->unsignedBigInteger('datum');
            $table->decimal('value', 24, 6)->default(0);
            $table->unsignedBigInteger('samples')->default(0);

            $table->unique(['stats_metric_id', 'period_eid', 'datum'], 'stats_values_unique');
            $table->index(['period_eid', 'datum'], 'stats_values_period_datum_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stats_values');
        Schema::dropIfExists('stats_metrics');
    }
};
