<?php

namespace App\Modules\Crm\Models;

use App\Models\User;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only audit/activity record. Immutable by contract: only created via
 * the activity log; never updated or deleted (no UPDATED_AT, no routes to mutate).
 */
#[Fillable(['event', 'description', 'properties', 'causer_id'])]
class Activity extends Model
{
    protected $table = 'crm_activities';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }

    /**
     * Ownership visibility — the single source of truth for every activity surface
     * (Dashboard "Recent Activity", Activity Logs, and any future notifications /
     * audit reports). Mirrors Lead::scopeVisibleTo so activity access follows lead
     * ownership. Admin / Super Admin see everything; a Business Development Manager
     * sees only:
     *   - activities they caused (their own lead actions + their own auth events), OR
     *   - activities whose subject is a lead they own (incl. assignments received from
     *     an Admin, and lifecycle events on their leads). withTrashed keeps a
     *     soft-deleted owned lead's "deleted" activity visible to its former owner.
     *
     * @param  Builder<Activity>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->hasAnyRole(['crm_admin', 'super_admin'])) {
            return;
        }

        $query->where(function (Builder $scoped) use ($user): void {
            $scoped->where('causer_id', $user->id)
                ->orWhere(function (Builder $ownedLead) use ($user): void {
                    $ownedLead->where('subject_type', (new Lead)->getMorphClass())
                        ->whereIn(
                            'subject_id',
                            Lead::withTrashed()->where('assigned_to', $user->id)->select('id'),
                        );
                });
        });
    }
}
