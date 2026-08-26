<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One frozen reading of a BD's performance. Immutable by convention — a snapshot that can be edited
 * is not a record of anything.
 *
 * Thin by design: capture goes through
 * {@see \App\Modules\Crm\Services\Performance\BdPerformanceSnapshotService}, which owns the ordering
 * rule that makes a snapshot correct at all (compute BEFORE the suspension is written, or the
 * ranking cohort has already excluded the very BD being captured).
 *
 * @property int $user_id
 * @property Carbon $captured_at Cast to datetime; declared so static analysis applies the cast.
 * @property string $reason
 * @property array<string, mixed> $metrics
 * @property array<string, mixed> $cohort
 */
#[Fillable(['captured_at', 'reason', 'metrics', 'cohort'])]
class BdPerformanceSnapshot extends Model
{
    protected $table = 'crm_bd_performance_snapshots';

    /** Capture reasons. `REASSIGNMENT` is used once a bulk handover can drain a portfolio. */
    public const REASON_SUSPENSION = 'suspension';

    public const REASON_REASSIGNMENT = 'reassignment';

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'metrics' => 'array',
            'cohort' => 'array',
        ];
    }

    /** The BD this reading belongs to. `user_id` is set by association, never mass-assigned. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
