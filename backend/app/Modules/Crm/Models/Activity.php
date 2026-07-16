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

    /**
     * CRM-reporting filter — the single source of truth for which activities participate in CRM
     * reporting and KPIs (Dashboard "Recent Activity", Activity Logs, M-06 Activity Volume, and any
     * future notification/audit-report surface built on this feed).
     *
     * Excludes every activity whose subject is an unqualified discovered PROSPECT ({@see
     * Lead::scopeProspect}) — a prospect lives in the Nearby Clients pool, outside CRM operations, so
     * its discovery/import/enrichment activity must not surface here or feed any metric. The moment a
     * prospect is qualified it is no longer a prospect, so ALL of its activity — including its original
     * discovery record — participates again: qualified/active leads keep their COMPLETE history.
     *
     * Deliberately orthogonal to {@see scopeVisibleTo}: this filter is about lifecycle participation,
     * that one about ownership. Compose both (`->visibleTo($user)->forCrmReporting()`); neither changes
     * the other. Non-lead subjects (auth events, notes, …) are untouched.
     *
     * @param  Builder<Activity>  $query
     */
    public function scopeForCrmReporting(Builder $query): void
    {
        $leadType = (new Lead)->getMorphClass();

        // Keep everything EXCEPT activities whose subject is an unqualified prospect lead. Written in
        // inclusive (keep-if) form so it is NULL-safe: a `NOT(subject_type = Lead AND …)` predicate
        // would evaluate to NULL — and be dropped — for subjectless activities (e.g. auth events),
        // silently excluding them from CRM reporting. Each branch below keeps a legitimate row:
        $query->where(function (Builder $keep) use ($leadType): void {
            $keep->where('subject_type', '!=', $leadType)                    // another model's activity
                ->orWhereNull('subject_type')                               // a subjectless activity
                ->orWhereNotIn('subject_id', Lead::prospect()->select('id')); // a lead, but not a prospect
        });
    }
}
