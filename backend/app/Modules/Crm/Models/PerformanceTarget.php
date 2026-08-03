<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Crm\Enums\PerformanceTargetMetric;
use App\Modules\Crm\Enums\PerformanceTargetPeriod;
use App\Modules\Crm\Observers\PerformanceTargetObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A performance goal for one Business Development Manager — the Goal Management domain behind BOTH
 * the monthly Personal Target and the quarterly Quarter Targets tiles.
 *
 * CONFIGURATION, NEVER A RESULT. A target supplies a denominator; it can never overwrite a computed
 * figure. Conversion Rate, Converted Leads, Revenue and the rest keep coming from
 * PerformanceSummaryService and the KPI Engine exactly as before.
 *
 * @property PerformanceTargetPeriod $period_type
 * @property PerformanceTargetMetric $metric
 * @property Carbon $period_start
 */
#[ObservedBy([PerformanceTargetObserver::class])]
#[Fillable([
    'user_id',
    'period_type',
    'period_start',
    'metric',
    'target_value',
    'is_active',
    'created_by',
])]
class PerformanceTarget extends Model
{
    use SoftDeletes;

    protected $table = 'crm_performance_targets';

    protected function casts(): array
    {
        return [
            'period_type' => PerformanceTargetPeriod::class,
            'metric' => PerformanceTargetMetric::class,
            'period_start' => 'date',
            // Cast so the id is an int no matter how it arrived (a JSON body sends it as a string).
            // Without this the activity trail records "2" for a target set through the UI and 2 for
            // one set in code — the same fact written two ways.
            'user_id' => 'integer',
            'created_by' => 'integer',
            'target_value' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Activity subject — performance-target.* events attach here (see PerformanceTargetObserver). */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    /**
     * The target that APPLIES: active, for this subject/metric, anchored to the period containing
     * $moment. Retired (`is_active = false`) and soft-deleted rows are excluded, so a retired target
     * restores the honest "no target configured" state rather than lingering as a denominator.
     */
    public function scopeApplying(
        Builder $query,
        int $userId,
        PerformanceTargetPeriod $period,
        PerformanceTargetMetric $metric,
        ?Carbon $moment = null,
    ): void {
        $query->where('user_id', $userId)
            ->where('period_type', $period->value)
            ->where('metric', $metric->value)
            ->where('period_start', $period->anchorFor($moment ?? Carbon::now()))
            ->where('is_active', true);
    }
}
