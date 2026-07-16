<?php

namespace App\Modules\Crm\Models;

use App\Models\User;

use App\Modules\Crm\Observers\LeadObserver;
use App\Modules\Crm\Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $last_activity Cast to datetime; declared so static analysis applies the cast.
 * @property Carbon|null $qualified_at Cast to datetime; declared so static analysis applies the cast.
 */
#[ObservedBy([LeadObserver::class])]
#[Fillable([
    'business_name',
    'contact_name',
    'email',
    'phone',
    'industry',
    'website_status',
    'priority',
    'email_outreach',
    'cold_calling',
    'status',
    'last_activity',
    'score',
    'amount',
    'region',
    'province',
    'city',
    'address',
    'latitude',
    'longitude',
    'place_id',
    'source',
    'website',
    'assigned_to',
    'created_by',
    'qualified_at',
    'qualified_by',
])]
class Lead extends Model
{
    protected $table = 'crm_leads';

    protected static function newFactory(): LeadFactory
    {
        return LeadFactory::new();
    }

    /** @use HasFactory<LeadFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Default attribute values — mirror the NOT-NULL column defaults in the leads migration so a
     * just-instantiated / just-created model carries the same baseline the database applies.
     *
     * Eloquent does NOT hydrate DB-level defaults back into the in-memory instance, so without this a
     * lead created without an explicit `website_status` reaches the `created` observer (the Lead Score
     * Engine) with a null value and the strictly-typed calculator fails. `'no-website'` is the approved
     * base website state (BD-PERF-COMP-SPEC §4.2) — a greenfield prospect (+25) — refined later only by
     * Website Intelligence. Keeping these in sync with the migration keeps the model, the database, and
     * the scoring engine consistent, and applies to every creation path (wizard, import, discovery).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'website_status' => 'no-website',
        'priority' => 'medium',
        'email_outreach' => 'none',
        'cold_calling' => 'not-called',
        'status' => 'new',
        'score' => 0,
    ];

    protected function casts(): array
    {
        return [
            'last_activity' => 'datetime',
            'qualified_at' => 'datetime',
            'score' => 'integer',
            // Estimated Deal Value (prospect-stage). NOT revenue — actual revenue lives on
            // `deals.amount`. Kept as a separate business concept.
            'amount' => 'decimal:2',
            // Discovery-provider coordinates (WGS84). Cast to float so the API emits numbers, not
            // fixed-precision strings. Stored only; the coordinate map is a later milestone.
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> The Business Developer who qualified this prospect into the CRM. */
    public function qualifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qualified_by');
    }

    /** @return MorphMany<Activity, $this> */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    /**
     * The most recent `deleted` Activity for this lead — the archive provenance the Archive
     * Management "Archived By" column derives its causer from. A one-of-many relation (latest by id
     * among `event = deleted`) so the archive list can eager-load it (`archiveActivity.causer`) with
     * no N+1 and no `deleted_by` column — the immutable audit trail is the source of truth.
     *
     * @return MorphOne<Activity, $this>
     */
    public function archiveActivity(): MorphOne
    {
        return $this->morphOne(Activity::class, 'subject')->ofMany(
            ['id' => 'max'],
            fn (Builder $query) => $query->where('event', 'deleted'),
        );
    }

    /**
     * Won conversions for this lead. A lead with ≥1 deal is a "converted lead"; the deals'
     * amounts are its contribution to Overall Revenue (BD Performance). See the Deal model.
     *
     * @return HasMany<Deal, $this>
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    /**
     * Internal notes on this lead, in chronological (oldest → newest) order — the order the
     * Lead Details "Internal Notes" thread renders.
     *
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class)->oldest();
    }

    /**
     * The full email conversation (both directions) with this lead — the read-only source for the
     * Lead Details "Email Thread" panel. Ordered chronologically by when each message actually
     * happened (inbound receipt / outbound send), falling back to row creation.
     *
     * @return HasMany<Email, $this>
     */
    public function emails(): HasMany
    {
        return $this->hasMany(Email::class)->orderByRaw('COALESCE(received_at, sent_at, created_at) ASC');
    }

    /**
     * Website Intelligence audit findings (1:1). Data-provider output only — availability, structure,
     * business info, and digital-presence observations. Never a Lead Score / Status / workflow field.
     *
     * @return HasOne<LeadWebsiteEnrichment, $this>
     */
    public function websiteEnrichment(): HasOne
    {
        return $this->hasOne(LeadWebsiteEnrichment::class);
    }

    /**
     * Ownership-visibility boundary — the single source of truth for who can see which leads.
     * Admin / Super Admin see every lead; a Business Development Manager sees only leads
     * assigned to them (so unassigned leads and other BDs' leads are hidden). Applied to the
     * leads list (and therefore Follow-ups + Export), and any other lead-based query.
     *
     * @param  Builder<Lead>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->hasAnyRole(['crm_admin', 'super_admin'])) {
            $query->where('assigned_to', $user->id);
        }
    }

    /** Whether the given user may see this lead (Admin/SA: all; BD: only their own). */
    public function isVisibleTo(User $user): bool
    {
        return $user->hasAnyRole(['crm_admin', 'super_admin']) || $this->assigned_to === $user->id;
    }

    /**
     * Server-side **Target Location** determination (data provider for the frozen Target Location
     * +10 signal — this SUPPLIES the data; it never applies the score, which the KPI Engine does).
     *
     * A lead is "in a target service location" when its admin field (region/province/city) matches
     * an **active** Service-Area registry entry at that entry's `level`. Pure string-equality
     * (whereIn) — DB-portable, no coordinates, no spatial SQL. Empty/absent registry → matches
     * nothing (a lead is never "in target" until the PO configures target areas).
     *
     * @param  Builder<Lead>  $query
     */
    public function scopeInTargetLocation(Builder $query): void
    {
        $areas = ServiceArea::query()->where('active', true)->get(['level', 'name']);
        $cities = $areas->where('level', 'city')->pluck('name')->all();
        $provinces = $areas->where('level', 'province')->pluck('name')->all();
        $regions = $areas->where('level', 'region')->pluck('name')->all();

        if (! $cities && ! $provinces && ! $regions) {
            $query->whereRaw('1 = 0'); // no target areas configured → no lead is in target

            return;
        }

        $query->where(function (Builder $q) use ($cities, $provinces, $regions) {
            if ($cities) {
                $q->orWhereIn('city', $cities);
            }
            if ($provinces) {
                $q->orWhereIn('province', $provinces);
            }
            if ($regions) {
                $q->orWhereIn('region', $regions);
            }
        });
    }

    /**
     * Leads landed by a discovery provider (the Nearby Clients prospect pool). `source` is set only
     * by the enrichment ingest, so this cleanly separates real discovered businesses from
     * hand-created / imported leads — provider-agnostic (it never checks *which* provider).
     *
     * @param  Builder<Lead>  $query
     */
    public function scopeDiscovered(Builder $query): void
    {
        $query->whereNotNull('source');
    }

    /**
     * The Prospect Pool — discovered businesses NOT yet qualified into the CRM. This is the Nearby
     * Clients surface: candidates awaiting a Business Developer's qualification decision. They never
     * participate in Business Computation.
     *
     * @param  Builder<Lead>  $query
     */
    public function scopeProspect(Builder $query): void
    {
        $query->whereNotNull('source')->whereNull('qualified_at');
    }

    /**
     * Active CRM Leads — the sales pipeline. A lead is active when it was entered deliberately
     * (`source` null: manual create / Excel import) OR a discovered prospect that has been qualified
     * (`qualified_at` set). **This is the scope Business Computation must query** (dashboards, Lead
     * Score aggregates, performance, pipeline, revenue). Unqualified prospects are excluded. The OR is
     * grouped so it composes safely with other constraints (e.g. `->visibleTo()`).
     *
     * @param  Builder<Lead>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            $q->whereNull('source')->orWhereNotNull('qualified_at');
        });
    }

    /**
     * Discovered prospects that have been qualified into the CRM (the subset of active leads that came
     * from discovery). Provenance stays on `source`; this reflects the lifecycle crossing only.
     *
     * @param  Builder<Lead>  $query
     */
    public function scopeQualified(Builder $query): void
    {
        $query->whereNotNull('qualified_at');
    }

    /** True while this lead is an unqualified discovered prospect (outside the CRM pipeline). */
    public function isProspect(): bool
    {
        return $this->source !== null && $this->qualified_at === null;
    }
}
