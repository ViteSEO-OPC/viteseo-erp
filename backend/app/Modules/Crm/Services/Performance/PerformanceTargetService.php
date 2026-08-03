<?php

namespace App\Modules\Crm\Services\Performance;

use App\Modules\Crm\Enums\PerformanceTargetMetric;
use App\Modules\Crm\Enums\PerformanceTargetPeriod;
use App\Modules\Crm\Models\PerformanceTarget;
use Illuminate\Support\Carbon;

/**
 * Goal Management writes and the one read path every consumer uses.
 *
 * Creation is restore-aware: the unique key covers soft-deleted rows, so re-creating a target that
 * was previously removed restores and updates that row rather than failing on a constraint the
 * caller cannot see. The alternative — a hard-delete on removal — would destroy the activity trail
 * the requirement asks for.
 */
class PerformanceTargetService
{
    /** @param array{user_id:int, period_type:string, period_start:string, metric:string, target_value:int, is_active?:bool} $data */
    public function create(array $data, ?int $actorId): PerformanceTarget
    {
        $period = PerformanceTargetPeriod::from($data['period_type']);
        // Normalise whatever date the caller sent to the period's canonical anchor, so a target set
        // for "the 17th" and one set for "the 1st" are the same target rather than two.
        $anchor = $period->anchorFor(Carbon::parse($data['period_start']));

        $existing = PerformanceTarget::withTrashed()
            ->where('user_id', $data['user_id'])
            ->where('period_type', $period->value)
            ->where('period_start', $anchor)
            ->where('metric', $data['metric'])
            ->first();

        if ($existing !== null) {
            $existing->restore();
            $existing->update([
                'target_value' => $data['target_value'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $existing;
        }

        return PerformanceTarget::create([
            ...$data,
            'period_start' => $anchor,
            'created_by' => $actorId,
        ]);
    }

    /** @param array{target_value?:int, is_active?:bool} $data */
    public function update(PerformanceTarget $target, array $data): PerformanceTarget
    {
        // Subject, period and metric are the target's identity — changing them would silently move a
        // goal to a different BD or quarter. Retire this one and set another instead.
        $target->update(array_intersect_key($data, array_flip(['target_value', 'is_active'])));

        return $target->fresh();
    }

    public function delete(PerformanceTarget $target): void
    {
        $target->delete();
    }

    /**
     * The applying target VALUE for a subject/metric/period, or null when none is configured.
     *
     * Null is the honest answer the UI needs: every consumer renders its existing Pending state
     * rather than a fabricated denominator, which is the behaviour the KB froze.
     */
    public function valueFor(
        int $userId,
        PerformanceTargetPeriod $period,
        PerformanceTargetMetric $metric,
        ?Carbon $moment = null,
    ): ?int {
        return PerformanceTarget::query()
            ->applying($userId, $period, $metric, $moment)
            ->value('target_value');
    }
}
