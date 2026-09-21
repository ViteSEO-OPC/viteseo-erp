<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;

use App\Modules\Crm\Enums\CallOutcome;
use App\Modules\Crm\Enums\CallStatus;
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
    // Lead Acquisition Channel — the business "Source" (Referral, Facebook, Website, Cold Outreach,
    // …). SEPARATE from `source` (discovery provenance); pure metadata, no business computation.
    'acquisition_channel',
    'website',
    // Digital Presence (social) + Marketplace Presence (e-commerce). All nullable/optional; plain
    // strings ready for clickable links / analytics / outreach / AI enrichment. `tiktok` (social
    // profile) is DISTINCT from `tiktok_shop` (marketplace storefront). "Website Domain" reuses
    // the existing `website` column above — no duplicate.
    'facebook',
    'instagram',
    'tiktok',
    'linkedin',
    'shopee',
    'lazada',
    'tiktok_shop',
    'other_marketplace',
    'assigned_to',
    'created_by',
    'qualified_at',
    'qualified_by',
])]
class Lead extends Model
{
    use BelongsToOrg;
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
     * Engine) with a null value and the strictly-typed calculator fails. `'website-unknown'` is the base
     * website state: nothing has checked yet, so nothing is claimed. It is scoring-NEUTRAL, and is
     * refined by Website Intelligence once a URL is fetched AND verified as the business's own. It
     * replaced `'no-website'`, which asserted the +25 greenfield-prospect signal about every lead whose
     * website had simply never been looked at. Keeping these in sync with the migration keeps the model,
     * the database, and the scoring engine consistent, and applies to every creation path (wizard,
     * import, discovery).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'website_status' => 'website-unknown',
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
     * Files uploaded against this lead — the source for the Lead Details "Attachments" card.
     * Newest-first, the order the card renders (and the order the composite
     * (lead_id, created_at) index serves).
     *
     * @return HasMany<LeadAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(LeadAttachment::class)->latest();
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
     * Every call attempt made to this lead across all its Planner Events — the source for the Lead
     * Details "Call History" projection. Ordered newest-first (most recent attempt at the top).
     *
     * @return HasMany<Call, $this>
     */
    public function calls(): HasMany
    {
        return $this->hasMany(Call::class)->latest();
    }

    /**
     * Inbound conversation threads (Messenger / Instagram DM / WhatsApp / web chat / Lead Ads)
     * associated with this lead. A lead may hold several — one per provider channel and contact —
     * and a thread may exist with no lead at all until a safe match is found, so this is a plain
     * reverse of the nullable `crm_conversations.lead_id`. Newest activity first.
     *
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class)->orderByRaw('COALESCE(last_message_at, created_at) DESC');
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
     * ENGAGED — the single canonical definition of "this lead has been contacted at all".
     *
     * **One predicate, three consumers**: M-04 Engagement Coverage (BdKpiService), the Conversion Rate
     * denominator + Outreach Progress counts (PerformanceSummaryService), and the Reports funnel's
     * `Contacted` stage (DashboardController). They previously carried three byte-identical copies of
     * the declared-field test, which could drift independently; this scope is now the only place the
     * rule lives.
     *
     * A lead is engaged when EITHER of two things is true:
     *
     *  **A. DECLARED / OFF-SYSTEM** — `email_outreach != 'none'` OR `cold_calling != 'not-called'`.
     *     Unchanged, and deliberately preserved: real business contact happens outside Zerem (a site
     *     visit, a personal mobile, mail from a BD's own client, a conversation at an event). The BD's
     *     declaration is the only record of it, so it must keep counting.
     *
     *  **B. ZEREM-VERIFIED ACTIVITY** — the platform itself holds proof that outreach happened, so the
     *     BD should not have to restate it by hand:
     *       - an outbound email that actually went out (`direction=outbound`, `status=sent`), or
     *       - a concluded call attempt (see below).
     *
     * This scope READS evidence; it never writes `email_outreach` or `cold_calling`. Those remain
     * manual BD controls, and Lead Score — which reads only those two columns — is untouched by design.
     *
     * EMAIL EVIDENCE is `status='sent'` and nothing else. `draft`, `scheduled`, `queued`, `sending`,
     * `failed` and `cancelled` are all excluded: only a delivered send is outreach. A Send-Later email
     * therefore starts NOT qualifying and begins qualifying at the moment delivery flips its row to
     * `sent` — which makes an immediate send and a successfully delivered scheduled send equivalent,
     * exactly as the frozen pipeline intends (both terminate in OutreachEmailService::sendExisting()).
     *
     * CALL EVIDENCE is a concluded call whose disposition PROVES a dial actually happened — see
     * {@see self::VERIFIED_CALL_OUTCOMES}. The rule is deliberately conservative: verified evidence
     * must prove an outreach attempt, so anything that cannot is left to the BD's manual declaration.
     *
     * Counts UNIQUE LEADS. `whereHas` is existence-only, so ten emails and five calls to one lead still
     * make it one engaged lead. `reorder()` strips the relations' default ORDER BY, which is useless
     * inside an EXISTS subquery.
     *
     * @param  Builder<Lead>  $query
     */
    /**
     * Call dispositions that PROVE an outreach attempt actually took place, for engagement evidence.
     *
     * Each of these is only reachable after the BD dialled and the call concluded, so each is proof of
     * effort — which is what M-04 measures ("is the BD working the whole book?"), not whether the
     * prospect picked up. `connected` reached them; `no_answer`, `busy` and `voicemail` did not, but a
     * dial demonstrably occurred.
     *
     * Deliberately EXCLUDED — none of them proves a dial:
     *  - `failed`      — ambiguous in the current lifecycle: it may be a technical failure to PLACE the
     *                    call rather than a real attempt, and `crm_calls` cannot distinguish the two.
     *                    Conservative reading: not evidence. (The enum keeps the case; only this
     *                    predicate ignores it. A BD who did attempt the call can still declare
     *                    `cold_calling` manually.)
     *  - `cancelled`   — the "Discard" action, documented as abandoning a session *without a real
     *                    disposition*.
     *  - NULL outcome / `in_progress` — the session never concluded.
     *
     * @var list<CallOutcome>
     */
    private const VERIFIED_CALL_OUTCOMES = [
        CallOutcome::CONNECTED,
        CallOutcome::NO_ANSWER,
        CallOutcome::BUSY,
        CallOutcome::VOICEMAIL,
    ];

    public function scopeEngaged(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            // A. Declared / off-system (unchanged behaviour).
            $q->where('email_outreach', '!=', 'none')
                ->orWhere('cold_calling', '!=', 'not-called')
                // B1. A real outbound email that actually went out.
                ->orWhereHas('emails', fn (Builder $e) => $e->reorder()
                    ->where('direction', 'outbound')
                    ->where('status', 'sent'))
                // B2. A concluded call that PROVES a dial actually happened.
                ->orWhereHas('calls', fn (Builder $c) => $c->reorder()
                    ->where('status', CallStatus::COMPLETED)
                    ->whereIn('outcome', self::VERIFIED_CALL_OUTCOMES));
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
