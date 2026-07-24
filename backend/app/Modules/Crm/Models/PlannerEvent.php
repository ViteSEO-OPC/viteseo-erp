<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Crm\Database\Factories\PlannerEventFactory;
use App\Modules\Crm\Observers\PlannerEventObserver;
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
     * events. Mirrors Lead::scopeVisibleTo / Activity::scopeVisibleTo (closes the deferred
     * Phase 2b Planner scoping). Admin / Super Admin see every event; a Business Development
     * Manager sees only events assigned to them OR linked to a lead they own.
     *
     * @param  Builder<PlannerEvent>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->hasAnyRole(['crm_admin', 'super_admin'])) {
            return;
        }

        $query->where(function (Builder $scoped) use ($user): void {
            $scoped->where('assigned_to', $user->id)
                ->orWhereIn(
                    'lead_id',
                    Lead::where('assigned_to', $user->id)->select('id'),
                );
        });
    }

    /** Whether the given user may see this event (Admin/SA: all; BD: own or on an owned lead). */
    public function isVisibleTo(User $user): bool
    {
        if ($user->hasAnyRole(['crm_admin', 'super_admin'])) {
            return true;
        }

        if ($this->assigned_to === $user->id) {
            return true;
        }

        return $this->lead_id !== null
            && Lead::where('id', $this->lead_id)->where('assigned_to', $user->id)->exists();
    }
}
