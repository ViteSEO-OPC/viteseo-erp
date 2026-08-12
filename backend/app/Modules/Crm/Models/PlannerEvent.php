<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Crm\Database\Factories\PlannerEventFactory;
use App\Modules\Crm\Observers\PlannerEventObserver;
use App\Modules\Crm\Services\Planner\PlannerOwnershipTransfer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A Planner scheduling record — the single scheduling entity for every CRM module (evolution of
 * the legacy Calendar). Named PlannerEvent (table `planner_events`) deliberately, to avoid
 * collision with future Activity/Audit/Notification/System-Event concepts.
 *
 * Two representations of one row: the Board groups by `status`; the calendar views render rows
 * where `starts_at` is set. `status` and scheduling are independent axes.
 *
 * `assigned_to`, `created_by`, and `source` are set server-side (never mass-assigned from the
 * client on create) — assignment mirrors lead ownership (BD self-assigns; Admin/SA pick a BD).
 * Every write logs an append-only `planner-event.*` Activity via PlannerEventObserver.
 *
 * @property int $id
 * @property string $event_type
 * @property string $status
 * @property int|null $lead_id
 * @property int|null $assigned_to
 * @property Carbon|null $starts_at Cast to datetime; declared so static analysis applies the cast.
 * @property Carbon|null $ends_at Cast to datetime; declared so static analysis applies the cast.
 */
#[ObservedBy([PlannerEventObserver::class])]
#[Fillable([
    'title',
    'event_type',
    'status',
    // Business result of a concluded event, separate from the `status` lifecycle. Consultation is the
    // only type defining values today ({@see ConsultationOutcome}); kept a plain string like
    // `status`/`event_type` so another type can adopt its own vocabulary later.
    'outcome',
    'priority',
    'starts_at',
    'ends_at',
    'all_day',
    'reminder_minutes',
    'source',
    'lead_id',
    'deal_id',
    'company_id',
    'campaign_id',
    'assigned_to',
    'created_by',
    'description',
])]
class PlannerEvent extends Model
{
    protected $table = 'crm_planner_events';

    /**
     * FOLLOW-UP WORK — the canonical business category (PO-approved, Model B).
     *
     * "Follow-up work" is work a BD owes a lead through some CHANNEL. `event_type` stores the channel;
     * the category is this set. Both existed before, but every consumer used the bare string
     * `'follow-up'` as a stand-in for the category — correct only while Lead Details discarded the
     * BD's channel choice and wrote `follow-up` for all of them.
     *
     * `follow-up` stays in the set as the LEGACY/GENERIC member: historical rows whose channel was
     * never captured, plus FollowUpScheduler's cold-call follow-ups. It is never rewritten and its
     * channel is never inferred (a title prefix is not evidence).
     *
     * EXCLUDED, deliberately:
     *  - `task` — EscalateUnansweredFollowUp creates these as the OUTPUT of completed follow-up work;
     *    counting them would let one BD action score twice.
     *  - `consultation` — a business milestone with its own KPI and its own `outcome` axis (PDR-002).
     */
    public const FOLLOW_UP_WORK_TYPES = ['follow-up', 'email', 'call', 'meeting'];

    /**
     * The "missed follow-ups" slice of {@see self::FOLLOW_UP_WORK_TYPES}.
     *
     * Reporting rule: the shared category is the full set, and a bucket narrows it ONLY where a
     * sibling bucket already reports that channel. `missedCalls` and `missedMeetings` exist, so
     * folding `call`/`meeting` into `missedFollowUps` would double-count the same overdue row in two
     * KPIs. No sibling reports `email`, so an overdue Email task belongs here — previously it was
     * counted nowhere at all.
     *
     * The three buckets therefore stay a PARTITION of overdue follow-up work.
     */
    public const MISSED_FOLLOW_UP_TYPES = ['follow-up', 'email'];

    /**
     * What marking a lead WON closes — the sales-CHASING channels only.
     *
     * Deliberately NOT {@see self::FOLLOW_UP_WORK_TYPES}, and this is the whole point of the constant:
     * that category includes `meeting`, and a won deal is precisely when onboarding/kickoff meetings
     * get booked. Closing the category wholesale would silently cancel real post-sale commitments.
     *
     * The asymmetry with LOST is intentional and is the business rule, not an oversight:
     *  - LOST  → nothing is owed at all, so the whole follow-up-work category closes (meeting included).
     *  - WON   → the pursuit is over, but the RELATIONSHIP has just started. Stop chasing
     *            (`follow-up`/`email`/`call`); keep every scheduled engagement (`meeting`), plus
     *            `task` and `consultation`, which sit outside the category already.
     */
    public const WON_CLOSES_TYPES = ['follow-up', 'email', 'call'];

    protected static function newFactory(): PlannerEventFactory
    {
        return PlannerEventFactory::new();
    }

    /** @use HasFactory<PlannerEventFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Model-level defaults — kept in lockstep with the migration's column defaults. Declared here
     * (not only in the DB) so a freshly created event returns its status/priority in the create
     * response without a refresh — the Board needs `status` to place the card in a column.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'todo',
        'priority' => 'medium',
        'all_day' => false,
        'source' => 'manual',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'completed_at' => 'datetime',
            'all_day' => 'boolean',
            'reminder_minutes' => 'integer',
        ];
    }

    /**
     * The lead/contact this event is about (nullable — an event need not target a lead).
     *
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * The won conversion this event relates to (future-ready).
     *
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * The owning Business Development Manager (owner avatar).
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The call attempts made against this event — the 1:many workflow where a BD may retry a
     * scheduled call several times. Ordered newest-first so the most recent attempt reads first.
     *
     * @return HasMany<Call, $this>
     */
    public function calls(): HasMany
    {
        return $this->hasMany(Call::class)->latest();
    }

    /**
     * Append-only Activity trail for this event — used when the event has no lead (a lead-linked
     * event logs against the lead instead, so it surfaces on that lead's timeline).
     *
     * @return MorphMany<Activity, $this>
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    /**
     * Ownership-visibility boundary — the single source of truth for who can see which planner
     * events. Admin / Super Admin see every event; a Business Development Manager sees exactly the
     * events ASSIGNED TO THEM. Mirrors Lead::scopeVisibleTo, which is the same strict rule.
     *
     * THE PLANNER IS "MY WORK", NOT "MY BOOK OF BUSINESS" (PO-approved). This scope previously also
     * admitted every event linked to a lead the BD owns, which produced two defects that were really
     * one: a BD saw cards stamped with another BD's name, and every Planner-derived KPI charged the
     * same row to two people at once — the assignee AND the lead owner.
     *
     * The lead-ownership branch is deliberately NOT replaced by anything here. Work follows ownership
     * structurally instead: {@see PlannerOwnershipTransfer} moves a lead's OPEN events to the new
     * owner on reassignment, so the assignee and the lead owner agree by construction rather than by
     * a query papering over the difference.
     *
     * COMPLETED work is the case that proves the rule. It is never transferred — `completed_at` plus
     * the Activity trail record who actually performed it — so a new lead owner does NOT see the
     * previous BD's completed events here, and must not: they are not their work. That history is
     * still fully visible to them on the LEAD's timeline, which is lead-scoped by
     * {@see Activity::scopeVisibleTo}. Planner answers "what do I owe?"; the lead timeline answers
     * "what has happened to this lead?" — two different questions, two different scopes.
     *
     * @param  Builder<PlannerEvent>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->hasAnyRole(['crm_admin', 'super_admin'])) {
            return;
        }

        $query->where('assigned_to', $user->id);
    }

    /**
     * The single expression of "this event is follow-up work" ({@see self::FOLLOW_UP_WORK_TYPES}).
     *
     * Every consumer that MEANS the category calls this instead of matching `event_type` by hand, so
     * the definition cannot drift the way three hand-written copies of `where('event_type','follow-up')`
     * could. Pass an explicit subset for a bucket that must stay disjoint from a sibling KPI — e.g.
     * {@see self::MISSED_FOLLOW_UP_TYPES}.
     *
     * NOT used by creation-time logic: FollowUpScheduler's dedupe guard asks a narrower question
     * ("does a generic follow-up already exist?"), and PlannerEventController routes on the literal
     * type. Both are deliberately left matching `'follow-up'` exactly.
     *
     * @param  Builder<PlannerEvent>  $query
     * @param  list<string>|null  $types
     */
    public function scopeFollowUpWork(Builder $query, ?array $types = null): void
    {
        $query->whereIn('event_type', $types ?? self::FOLLOW_UP_WORK_TYPES);
    }

    /**
     * Whether the given user may see this event (Admin/SA: all; BD: only their own).
     *
     * The row-level twin of {@see scopeVisibleTo()} — PlannerEventPolicy composes BOTH (the scope for
     * the list, this for single-record view/update/delete), so the two must express the identical
     * rule. If they ever diverge, a BD can reach by id something the list would never show them.
     */
    public function isVisibleTo(User $user): bool
    {
        if ($user->hasAnyRole(['crm_admin', 'super_admin'])) {
            return true;
        }

        return $this->assigned_to === $user->id;
    }
}
