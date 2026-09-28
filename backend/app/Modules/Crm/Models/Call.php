<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;
use App\Modules\Crm\Database\Factories\CallFactory;
use App\Modules\Crm\Enums\CallDirection;
use App\Modules\Crm\Enums\CallDurationSource;
use App\Modules\Crm\Enums\CallOutcome;
use App\Modules\Crm\Enums\CallStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A single call attempt in the Calling Workspace. Planner-driven: a Call is a SUBORDINATE detail
 * record that can never exist without its Planner Event ({@see self::plannerEvent()} is non-optional),
 * so Planner stays the single source of truth for the scheduled activity. One Planner Event may carry
 * many attempts (1:many); CallSessionService — not this model — enforces at most one active
 * (`in_progress`) call per event and owns every state transition. This model is a thin, business-logic-free
 * data record: casts, ownership relationships, and nothing more.
 *
 * The row holds only call-specific METADATA. Business state (the timeline) stays Activity-driven:
 * `call.started` / `call.completed` are logged to crm_activities against the lead, and
 * `planner-event.completed` is logged by PlannerEventObserver — never duplicated here.
 *
 * @property int $id
 * @property int $planner_event_id
 * @property int $lead_id
 * @property int $user_id
 * @property CallDirection $direction Cast to enum; declared so static analysis applies the cast.
 * @property string $provider Vendor-neutral transport tag (open string — see CallDirection docblock).
 * @property CallStatus $status Cast to enum; declared so static analysis applies the cast.
 * @property CallOutcome|null $outcome Cast to enum; null until the call is completed.
 * @property Carbon|null $started_at Cast to datetime; declared so static analysis applies the cast.
 * @property Carbon|null $ended_at Cast to datetime; declared so static analysis applies the cast.
 * @property int|null $duration_seconds
 * @property CallDurationSource $duration_source Cast to enum; who measured $duration_seconds.
 * @property array<string, mixed>|null $signals Cast to array; RESERVED (unused in P1).
 */
#[Fillable([
    // Descriptive / metadata fields the orchestration service passes through. The ownership FKs
    // (planner_event_id, lead_id, user_id) are deliberately NOT here — they are set via the
    // relationships / explicit association in CallSessionService, never mass-assigned from a client.
    'direction',
    'provider',
    // The E.164 number handed to a carrier for this attempt. Null for a manual call — the CRM does
    // not dial then, so it does not claim to know what was.
    'to_number',
    'status',
    'outcome',
    'started_at',
    'ended_at',
    'duration_seconds',
    // Whether $duration_seconds is the CRM's own measurement or a carrier's verified talk time.
    'duration_source',
    'notes',
    'signals',
    'external_call_id',
    'recording_url',
    'transcript',
    'ai_summary',
])]
class Call extends Model
{
    use BelongsToOrg;
    protected $table = 'crm_calls';

    protected static function newFactory(): CallFactory
    {
        return CallFactory::new();
    }

    /** @use HasFactory<CallFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Model-level defaults — kept in lockstep with the migration's column defaults so a
     * just-instantiated Call carries the same baseline the database applies (Eloquent does not
     * hydrate DB-level defaults back into the in-memory instance).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'direction' => CallDirection::OUTBOUND->value,
        'provider' => 'manual',
        'status' => CallStatus::IN_PROGRESS->value,
    ];

    protected function casts(): array
    {
        return [
            'direction' => CallDirection::class,
            'status' => CallStatus::class,
            'outcome' => CallOutcome::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'duration_source' => CallDurationSource::class,
            'signals' => 'array',
        ];
    }

    /**
     * The Planner Event this call attempt belongs to — the authority that scheduled the activity.
     * Non-optional: `planner_event_id` is NOT NULL, so a Call always resolves an event.
     *
     * @return BelongsTo<PlannerEvent, $this>
     */
    public function plannerEvent(): BelongsTo
    {
        return $this->belongsTo(PlannerEvent::class);
    }

    /**
     * The lead this call is about (required — every call belongs to exactly one lead).
     *
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * The Business Developer who placed the call (required — call attribution is preserved).
     *
     * @return BelongsTo<User, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
