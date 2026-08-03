<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Crm\Enums\ConsultationOutcome;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Requests\PerformanceScopeRequest;
use App\Modules\Crm\Resources\LeadResource;
use App\Modules\Crm\Services\Kpi\BdKpiService;
use App\Modules\Crm\Services\Kpi\KpiMetric;
use App\Modules\Crm\Services\Ranking\BdRankingRow;
use App\Modules\Crm\Services\Ranking\BdRankingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /**
     * The pending follow-up set — the ONE definition behind every follow-up queue figure this
     * controller serves (the dashboard's Follow-ups Due KPI and the reports page's overdue/today/
     * upcoming buckets).
     *
     * Frozen rule (Reports & Performance milestone): a Planner event of type `follow-up` that is
     * DATED and NOT completed, scoped by PlannerEvent::visibleTo — Admin/SA see the whole CRM, a BD
     * sees their own events plus events on the leads they own. An outbound email is deliberately NOT
     * a follow-up (PO ruling 2026-07-09); only real Planner events count here.
     *
     * Returned as a builder factory rather than a builder so each caller gets a fresh query — the
     * bucket counts below run four of them off this one definition.
     *
     * @return callable(): Builder<PlannerEvent>
     */
    private function pendingFollowUps(User $user): callable
    {
        return fn (): Builder => PlannerEvent::query()
            ->visibleTo($user)
            ->where('event_type', 'follow-up')
            ->where('status', '!=', 'completed')
            ->whereNotNull('starts_at');
    }

    /**
     * The Asia/Manila day boundary as half-open UTC windows, `[todayStart, todayEnd)`.
     *
     * Datetimes are stored UTC but the business day is Manila's (the platform rule), and the windows
     * are half-open so an event landing exactly on midnight is counted once — never in two buckets.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function manilaDayWindow(): array
    {
        $tz = 'Asia/Manila';

        return [now($tz)->startOfDay()->utc(), now($tz)->startOfDay()->addDay()->utc()];
    }

    /**
     * Supported KPI counts (backend-computable only) + per-status breakdown.
     * Scoped by ownership: Admin/SA see all leads; a BD sees only their own (Lead::scopeVisibleTo).
     */
    public function kpis(Request $request): JsonResponse
    {
        $user = $request->user();

        // Single status aggregation (same groupBy pattern as the website-status buckets);
        // powers both the `interested` KPI and the Lead Status Summary tab counts —
        // no duplicate aggregation, no new endpoint.
        $statusCounts = Lead::query()
            ->active()
            ->visibleTo($user)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'data' => [
                'totalLeads' => Lead::query()->active()->visibleTo($user)->count(),
                'highPriority' => Lead::query()->active()->visibleTo($user)->where('priority', 'high')->count(),
                'interested' => (int) $statusCounts->get('interested', 0),
                // Follow-ups Due — the WHOLE scoped pending set (overdue + today + upcoming), which
                // is the same "actionable items" reading the Planner sidebar badge already uses. It
                // rides the shared pendingFollowUps() definition, so this card and the reports
                // page's three buckets can never drift apart: this is their sum by construction.
                'followUpsDue' => ($this->pendingFollowUps($user))()->count(),
                // Completed Outreach (PO decision) — outbound emails the system actually SENT.
                //
                // Counts ACTIONS, not leads: emailing one lead three times is three completed
                // outreach actions, which is what makes this an effort metric rather than a
                // coverage one (coverage is M-04, deliberately a different KPI).
                //
                // `crm_emails.status = 'sent'` is the source because it is written by the send
                // path itself. `crm_leads.email_outreach` is deliberately NOT used: nothing in the
                // codebase ever writes its `sent` value — only `replied`, by the inbound processor
                // — so it records what happened TO us, not what we did. Using it would let an
                // inbound reply credit outreach that was never performed.
                //
                // Every other status is excluded by being unequal to 'sent': draft, scheduled,
                // queued, failed, cancelled. Inbound mail cannot leak in — it is a different
                // direction AND carries `received`. Calls and Planner follow-ups are out of scope
                // by PO decision; no combined channel rule is invented here.
                'completedOutreach' => Email::query()
                    ->where('direction', 'outbound')
                    ->where('status', 'sent')
                    ->whereIn('lead_id', Lead::query()->active()->visibleTo($user)->select('id'))
                    ->count(),
                // Raw per-status counts (frozen enum keys). The UI applies its own
                // label mapping — keeping this response terminology-neutral.
                'statusCounts' => [
                    'new' => (int) $statusCounts->get('new', 0),
                    'interested' => (int) $statusCounts->get('interested', 0),
                    'awaiting-reply' => (int) $statusCounts->get('awaiting-reply', 0),
                    'call-back-req' => (int) $statusCounts->get('call-back-req', 0),
                    'declined' => (int) $statusCounts->get('declined', 0),
                ],
            ],
        ]);
    }

    /**
     * Dashboard Copilot AI summary metrics.
     *
     * TODO: these are CENTRALIZED PLACEHOLDER values. No AI scoring engine exists yet — the
     * lead `score` is a plain stored column and there is no re-score / priority-move tracking,
     * so these numbers have no real source to compute from (deliberately NOT fabricated here).
     * Once the AI scoring engine lands, replace these with real metrics: leads re-scored and
     * leads promoted to High priority in the last run, plus the actual run timestamp.
     */
    public function copilotSummary(): JsonResponse
    {
        return response()->json([
            'data' => [
                'rescoredLeads' => 18,
                'movedToHigh' => 4,
                'generatedAt' => '2026-07-03T11:20:00+00:00',
            ],
        ]);
    }

    /** Latest leads (newest first) — same newest-by-id ordering as the Leads list, so
     * just-created/imported records surface here too (they have no last_activity).
     * Ownership-scoped: a BD only sees their own recent leads. */
    public function recentLeads(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $leads = Lead::query()
            ->active()
            ->visibleTo($user)
            ->latest('id')
            ->limit(5)
            ->get();

        return LeadResource::collection($leads)
            ->additional(['meta' => ['total' => Lead::query()->active()->visibleTo($user)->count()]]);
    }

    /**
     * "BD Performance Overview" — one live operational row per active Business Development
     * Manager (Admin/SA Management Dashboard only; route-guarded to those roles).
     *
     * Every active BD is aggregated automatically, so a newly-created BD account appears here
     * with no code change. Only metrics with a legitimate, unambiguous backend source today are
     * returned:
     *   - assigned = leads assigned to the BD (leads.assigned_to) — a raw count, no business rule
     *
     * Deliberately NOT returned: follow-ups, converted leads, conversion rate, and any performance
     * score / status. There is no follow-ups backend (an outbound email is not, by definition, a
     * follow-up — mapping the two would invent a business rule), and the lead-status enum has no
     * converted/won state. Those columns stay display-only placeholders on the frontend until the
     * required business data exists and the KPI Engine milestone lands. No proxy is invented here.
     */
    public function bdPerformance(): JsonResponse
    {
        // Active Business Development Managers, name-ordered. `whereHas('roles')` matches the
        // same role slug the seeder + User Management use; `status = active` mirrors the sign-in gate.
        $bds = User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn (Builder $q) => $q->where('name', 'crm_bd'))
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($bds->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $ids = $bds->pluck('id');

        // Assigned leads per BD — one GROUP BY, no N+1. DB is the source of truth.
        $assigned = Lead::query()
            ->active()
            ->whereIn('assigned_to', $ids)
            ->selectRaw('assigned_to, COUNT(*) as count')
            ->groupBy('assigned_to')
            ->pluck('count', 'assigned_to');

        $rows = $bds->map(fn (User $bd): array => [
            'id' => (string) $bd->id,
            'name' => $bd->name,
            'initials' => $this->initialsFor($bd->name),
            'assigned' => (int) ($assigned->get($bd->id) ?? 0),
        ])->values()->all();

        return response()->json(['data' => $rows]);
    }

    /**
     * "Lead Acquisition" — raw count of NEW leads per calendar month over the last 6 months
     * (rolling window, oldest→newest). One `COUNT(*)` per month bucket on `leads.created_at`; every
     * newly created or imported lead lands in its month bucket automatically. Ownership-scoped via
     * `Lead::visibleTo` (Admin/SA = all leads).
     *
     * Descriptive only: the per-month counts, their total, the monthly average, and the current
     * calendar month's count. NO month-over-month %, growth, trend, forecast, or interpretation is
     * computed — those are deferred to the KPI Engine. DB is the single source of truth.
     */
    public function leadAcquisition(Request $request): JsonResponse
    {
        $user = $request->user();

        // Anchor to the first day of the month 5 months back, so the current month is the last of
        // 6 buckets. Buckets are generated up front → a month with no new leads still renders a 0 bar.
        $start = now()->startOfMonth()->subMonthsNoOverflow(5);

        // One COUNT per calendar-month bucket over the ownership-scoped leads. Per-month counting
        // (vs a DB-specific DATE_FORMAT/strftime GROUP BY) keeps this portable across MySQL and the
        // SQLite test suite, and makes every bar a literal COUNT(*). Every newly created/imported
        // lead lands in its month automatically.
        $points = [];
        $total = 0;
        $currentMonthCount = 0;
        for ($i = 0; $i < 6; $i++) {
            $month = (clone $start)->addMonthsNoOverflow($i);
            $count = Lead::query()
                ->active()
                ->visibleTo($user)
                ->whereBetween('created_at', [(clone $month)->startOfMonth(), (clone $month)->endOfMonth()])
                ->count();
            $total += $count;
            $points[] = ['month' => $month->format('M'), 'count' => $count];
            $currentMonthCount = $count; // last iteration (i=5) is the current calendar month
        }

        return response()->json(['data' => [
            'points' => $points,
            'totalNew' => $total,
            'monthlyAverage' => (int) round($total / 6),
            'currentMonthCount' => $currentMonthCount,
        ]]);
    }

    /**
     * "My Performance" personal KPI summary (BD-facing surface S1), ownership-scoped via
     * `Lead::scopeVisibleTo` — a BD sees only their own portfolio; Admin/SA see all visible leads.
     *
     * All computation is server-side (the frontend renders these numbers, it does not derive them):
     *   - assignedLeads   : visible leads (for a BD == leads assigned to them)
     *   - activeLeads     : visible leads in the frozen ACTIVE_SET (BD-PERF-COMP-SPEC R-1)
     *   - engagedLeads    : visible leads with any outreach or any calling (M-04 Engagement basis)
     *   - convertedLeads  : visible leads with ≥1 deal (won conversion)
     *   - overallRevenue  : Σ deals.amount across visible leads (realized revenue — Deals only)
     *   - estimatedPipelineValue : Σ leads.amount across visible leads (prospect-stage Estimated Deal
     *                       Value — NOT revenue; distinct field, distinct source)
     *   - conversionRate  : convertedLeads ÷ engagedLeads × 100 (approved denominator), 1 dp;
     *                       null when engagedLeads == 0 (undefined — never 0%, frozen edge rule)
     *
     * Converted/revenue come from the `deals` table (approved conversion record) — the frozen Lead
     * Status enum is untouched. Completed Follow-ups + Follow-up discipline are now computed from the
     * Planner module (real events, real `completed_at`); Personal Target stays a placeholder (no monthly
     * goal source — PO decision) and outreach coverage stays the KPI Engine's M-04. No proxy or invented
     * business rule.
     *
     * Scope: resolved by {@see PerformanceScopeRequest}. Default = the requester's own portfolio (a BD's
     * My Performance; an Admin/SA's "All Reports" aggregate). `?user_id` drills into one BD and is
     * management-only (`crm.reports.view`). The whole method body scopes through the resolved subject.
     */
    public function performanceSummary(PerformanceScopeRequest $request): JsonResponse
    {
        $user = $request->scope()->subject;

        // Frozen ACTIVE_SET (BD-PERF-COMP-SPEC R-1): all statuses except `declined`.
        $activeSet = ['new', 'interested', 'awaiting-reply', 'call-back-req'];

        $assignedLeads = Lead::query()->active()->visibleTo($user)->count();
        $activeLeads = Lead::query()->active()->visibleTo($user)->whereIn('status', $activeSet)->count();

        // Engagement basis (M-04): any outreach started OR any calling activity.
        $engagedLeads = Lead::query()->active()->visibleTo($user)
            ->where(fn (Builder $q) => $q->where('email_outreach', '!=', 'none')
                ->orWhere('cold_calling', '!=', 'not-called'))
            ->count();

        // Converted leads + revenue from the deals table, scoped to the user's visible leads.
        $convertedLeads = Lead::query()->active()->visibleTo($user)->has('deals')->count();
        $visibleLeadIds = Lead::query()->active()->visibleTo($user)->pluck('id');
        // REVENUE = realized, won business → Σ deals.amount ONLY (never leads.amount). Unchanged.
        $overallRevenue = $visibleLeadIds->isEmpty()
            ? 0.0
            : (float) Deal::query()->whereIn('lead_id', $visibleLeadIds)->sum('amount');

        // ESTIMATED PIPELINE VALUE = prospect-stage Estimated Deal Value → Σ leads.amount, ownership-
        // scoped. Distinct from Revenue: this is the sum of `leads.amount` (an estimate on open leads),
        // NOT realized revenue. Single source of truth for the pipeline figure — computed only here.
        $estimatedPipelineValue = (float) Lead::query()->active()->visibleTo($user)->sum('amount');

        // Conversion Rate = converted ÷ engaged (approved). Zero denominator → null (undefined).
        $conversionRate = $engagedLeads > 0
            ? round($convertedLeads / $engagedLeads * 100, 1)
            : null;

        // Planner-based follow-up metrics — scoped exactly like the lead metrics, via
        // PlannerEvent::visibleTo (a BD sees their own events, Admin/SA all).
        //
        // Follow-up discipline (PO-frozen rule): "missed" = an event whose `starts_at` is in the past
        // and whose `status` is not `completed` — grouped by event_type (follow-up / meeting / call).
        $missedFor = fn (string $type): int => PlannerEvent::query()
            ->visibleTo($user)
            ->where('event_type', $type)
            ->whereNotNull('starts_at')
            ->where('starts_at', '<', now())
            ->where('status', '!=', 'completed')
            ->count();

        // Completed Follow-ups — count for the CURRENT month, timestamped by the real `completed_at`
        // (never `updated_at`). Rows completed before `completed_at` existed carry null and are excluded.
        $completedFollowUps = PlannerEvent::query()
            ->visibleTo($user)
            ->where('event_type', 'follow-up')
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->startOfMonth())
            ->count();

        return response()->json(['data' => [
            'assignedLeads' => $assignedLeads,
            'activeLeads' => $activeLeads,
            'engagedLeads' => $engagedLeads,
            'convertedLeads' => $convertedLeads,
            'overallRevenue' => $overallRevenue,
            'estimatedPipelineValue' => $estimatedPipelineValue,
            'conversionRate' => $conversionRate,
            'completedFollowUps' => $completedFollowUps,
            'followUpDiscipline' => [
                'missedFollowUps' => $missedFor('follow-up'),
                'missedMeetings' => $missedFor('meeting'),
                'missedCalls' => $missedFor('call'),
            ],
        ]]);
    }

    /**
     * Reports & Performance management analytics — the deeper aggregate the Management surface renders
     * below the KPI row (Admin/SA see the whole-CRM aggregate; a BD would see only their own portfolio).
     * ONE ownership-scoped read (same {@link PerformanceScopeRequest} seam as performanceSummary — All
     * Reports, or one BD via `?user_id`); every figure is a real, server-computed aggregate over the
     * subject's visible leads / Planner events. No fabricated numbers, and — per the frozen Business
     * Decision — **no goals or targets are invented**: `followThrough.targetsPending` is surfaced so the
     * UI shows the live counts without a made-up denominator (Goal Management is a later sprint).
     *
     * Stage/channel definitions are documented inline because the mockup labels don't map 1:1 to the
     * frozen `status` enum — each is derived from a concrete, existing lead field:
     *   Funnel  Added=all active leads · Contacted=outreach started or called (M-04 engagement) ·
     *           Interested=`status=interested` · Consultation=`cold_calling=scheduled` (a booked slot) ·
     *           Confirmed=has ≥1 Deal (won).
     *           NOTE: the funnel's `consultation` stage still measures SCHEDULED slots — a different
     *           question from `consultationsHeld` below (consultations actually held, per PDR-002).
     *           The funnel is deliberately untouched pending the Funnel Review milestone, which
     *           redesigns every stage together rather than changing one in isolation.
     *   Outreach Email  completed=`replied` · in-progress=`sent`+`opened` · not-started=`none`.
     *           Call   completed=`called` · in-progress=`scheduled` · not-started=`not-called`.
     */
    public function reportsAnalytics(PerformanceScopeRequest $request): JsonResponse
    {
        $user = $request->scope()->subject;

        // Fresh scoped lead query per use (the `active()` set — every status incl. declined — visible to the subject).
        $leads = fn (): Builder => Lead::query()->active()->visibleTo($user);

        // ---- Conversion funnel — a true SURVIVAL funnel: each qualification stage is a subset of the
        // one before it (cumulative AND filters), so the funnel narrows monotonically and each bar reads
        // as "leads that progressed at least this far". Definitions in the method docblock. ----
        $engaged = fn (): Builder => $leads()
            ->where(fn (Builder $q) => $q->where('email_outreach', '!=', 'none')
                ->orWhere('cold_calling', '!=', 'not-called'));
        // "In active conversation" statuses (responded / progressing) — engaged beyond `new`, excl. `declined`.
        $activeConversation = ['interested', 'awaiting-reply', 'call-back-req'];

        $added = $leads()->count();
        $contacted = $engaged()->count();
        $interested = $engaged()->whereIn('status', $activeConversation)->count();
        $consultation = $engaged()->whereIn('status', $activeConversation)->where('cold_calling', 'scheduled')->count();
        // Confirmed = realized won business (Σ leads with ≥1 Deal) — the true outcome total. It may include
        // leads that converted through a path outside the modeled qualification signals, so it is NOT nested.
        $confirmed = $leads()->has('deals')->count();

        $funnelStages = [
            ['key' => 'added', 'label' => 'Added', 'value' => $added],
            ['key' => 'contacted', 'label' => 'Contacted', 'value' => $contacted],
            ['key' => 'interested', 'label' => 'Interested', 'value' => $interested],
            ['key' => 'consultation', 'label' => 'Consultation', 'value' => $consultation],
            ['key' => 'confirmed', 'label' => 'Confirmed', 'value' => $confirmed],
        ];

        // Biggest consecutive stage-to-stage drop-off (largest % loss) — presentation over the real counts.
        $biggestDropOff = null;
        for ($i = 1; $i < count($funnelStages); $i++) {
            $prev = $funnelStages[$i - 1];
            $cur = $funnelStages[$i];
            if ($prev['value'] <= 0) {
                continue;
            }
            $dropPct = (int) round(($cur['value'] - $prev['value']) / $prev['value'] * 100);
            if ($biggestDropOff === null || $dropPct < $biggestDropOff['dropPct']) {
                $biggestDropOff = ['from' => $prev['label'], 'to' => $cur['label'], 'dropPct' => $dropPct];
            }
        }

        // ---- Lead status mix (GROUP BY status) — raw counts; the frontend maps labels/colors from the
        // central LEAD_STATUS_CONFIG so the taxonomy stays single-sourced. ----
        $statusMix = $leads()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        // ---- Outreach progress by channel (real field distributions) ----
        $emailCounts = $leads()->selectRaw('email_outreach, COUNT(*) as c')->groupBy('email_outreach')->pluck('c', 'email_outreach');
        $callCounts = $leads()->selectRaw('cold_calling, COUNT(*) as c')->groupBy('cold_calling')->pluck('c', 'cold_calling');

        $emailCompleted = (int) ($emailCounts['replied'] ?? 0);
        $emailInProgress = (int) (($emailCounts['sent'] ?? 0) + ($emailCounts['opened'] ?? 0));
        $emailNotStarted = (int) ($emailCounts['none'] ?? 0);
        $emailTotal = $emailCompleted + $emailInProgress + $emailNotStarted;

        $callCompleted = (int) ($callCounts['called'] ?? 0);
        $callInProgress = (int) ($callCounts['scheduled'] ?? 0);
        $callNotStarted = (int) ($callCounts['not-called'] ?? 0);
        $callTotal = $callCompleted + $callInProgress + $callNotStarted;

        $outreachByChannel = [
            'email' => ['completed' => $emailCompleted, 'inProgress' => $emailInProgress, 'notStarted' => $emailNotStarted, 'total' => $emailTotal],
            'call' => ['completed' => $callCompleted, 'inProgress' => $callInProgress, 'notStarted' => $callNotStarted, 'total' => $callTotal],
        ];

        // ---- Leads by niche (GROUP BY industry — industry IS the niche; no taxonomy mapping) ----
        $niche = $leads()
            ->whereNotNull('industry')
            ->where('industry', '!=', '')
            ->selectRaw('industry, COUNT(*) as c')
            ->groupBy('industry')
            ->orderByDesc('c')
            ->limit(8)
            ->get()
            ->map(fn ($r): array => ['niche' => (string) $r->industry, 'value' => (int) $r->c])
            ->values();

        // ---- Quarter follow-through — live counts only. The PO-frozen rule: no fabricated targets, so
        // the goal denominators are omitted (`targetsPending`) and the UI shows the raw numerators. ----
        $missedFor = fn (string $type): int => PlannerEvent::query()
            ->visibleTo($user)
            ->where('event_type', $type)
            ->whereNotNull('starts_at')
            ->where('starts_at', '<', now())
            ->where('status', '!=', 'completed')
            ->count();

        $outreachCompletionPct = ($emailTotal + $callTotal) > 0
            ? (int) round(($emailCompleted + $callCompleted) / ($emailTotal + $callTotal) * 100)
            : null;

        // ---- Consultations — 🔒 FROZEN business rule (PDR-002 §4b) ----------------------------------
        //
        // A consultation is a Planner event; it counts once its lifecycle is `completed` AND its business
        // outcome says the lead actually attended. The attended set is owned by ConsultationOutcome —
        // NEVER re-list the values here, or the rule silently drifts from the enum.
        //
        // Restricted to leads in the Active CRM set so this shares the page's denominator (the unqualified
        // Nearby prospect pool is excluded everywhere else too).
        $consultations = fn (): Builder => PlannerEvent::query()
            ->visibleTo($user)
            ->where('event_type', 'consultation')
            ->where('status', 'completed')
            ->whereHas('lead', fn (Builder $q) => $q->active());

        // KPI — UNIQUE LEADS that underwent a consultation (many consultations on one lead → 1).
        $consultationsHeld = $consultations()
            ->whereIn('outcome', ConsultationOutcome::attendedValues())
            ->distinct()
            ->count('lead_id');

        // OPERATIONAL QUEUE (not a KPI) — consultations concluded but with no outcome recorded yet, e.g.
        // completed by a connected call. `null` means AWAITING BUSINESS COMPLETION, never attendance, so
        // these are excluded from the KPI above and surfaced here as work for the BD. Counted as EVENTS,
        // not distinct leads: each row is one record that still needs a result.
        $consultationsAwaitingOutcome = $consultations()->whereNull('outcome')->count();

        $followThrough = [
            'leadsContacted' => $contacted,
            // Consultations actually HELD (frozen rule) — not the funnel's scheduled-slot stage.
            'consultations' => $consultationsHeld,
            'consultationsAwaitingOutcome' => $consultationsAwaitingOutcome,
            'confirmedClients' => $confirmed,
            'missedFollowUps' => $missedFor('follow-up'),
            'missedMeetings' => $missedFor('meeting'),
            'missedCalls' => $missedFor('call'),
            'outreachCompletionPct' => $outreachCompletionPct,
            // No goals invented — Goal Management is a later sprint (frozen Business Decision).
            'targetsPending' => true,
        ];

        // ---- Pending follow-ups — the team's action queue. Overdue/today/upcoming by the Asia/Manila
        // day boundary (dates stored UTC, day computed in Manila — the platform rule). Both the set
        // and the window come from the shared helpers, so the dashboard's Follow-ups Due KPI counts
        // exactly the rows these three buckets partition. ----
        [$todayStart, $todayEnd] = $this->manilaDayWindow();

        $pending = $this->pendingFollowUps($user);

        $rows = $pending()
            ->with('lead:id,business_name')
            ->orderBy('starts_at')
            ->limit(10)
            ->get()
            ->map(function (PlannerEvent $e) use ($todayStart, $todayEnd): array {
                $status = $e->starts_at < $todayStart
                    ? 'overdue'
                    : ($e->starts_at < $todayEnd ? 'today' : 'upcoming');

                return [
                    'id' => $e->id,
                    'status' => $status,
                    'company' => $e->lead?->business_name ?? '—',
                    'description' => $e->title,
                    'dueDate' => $e->starts_at?->toIso8601String(),
                ];
            })
            ->values();

        $pendingFollowUps = [
            'counts' => [
                // Half-open [start, end) windows — identical to the row classifier above, so an event
                // landing exactly on the Manila midnight boundary is counted once (in `upcoming`),
                // never in two buckets. The three counts always sum to the pending total.
                'overdue' => (clone $pending())->where('starts_at', '<', $todayStart)->count(),
                'today' => (clone $pending())
                    ->where('starts_at', '>=', $todayStart)
                    ->where('starts_at', '<', $todayEnd)
                    ->count(),
                'upcoming' => (clone $pending())->where('starts_at', '>=', $todayEnd)->count(),
            ],
            'rows' => $rows,
        ];

        return response()->json(['data' => [
            'funnel' => ['stages' => $funnelStages, 'biggestDropOff' => $biggestDropOff],
            // Unique leads that underwent a consultation. Distinct from the funnel's `consultation`
            // stage, which still measures SCHEDULED slots and is untouched pending the Funnel Review.
            'consultationsHeld' => $consultationsHeld,
            'consultationsAwaitingOutcome' => $consultationsAwaitingOutcome,
            'statusMix' => $statusMix,
            'outreachByChannel' => $outreachByChannel,
            'niche' => $niche,
            'followThrough' => $followThrough,
            'pendingFollowUps' => $pendingFollowUps,
        ]]);
    }

    /**
     * BD operational KPI catalog (BD-PERF-COMP-SPEC §3, M-01…M-07) — the explainable breakdown from
     * the BD KPI Engine, computed entirely server-side from real CRM data and ownership-scoped via
     * `Lead::scopeVisibleTo` / `Activity::scopeVisibleTo` (a BD sees only their own portfolio; Admin/SA
     * see all visible). A metric with no computable value (e.g. a zero denominator) is returned as
     * `evaluable: false` with a `reason` and a null value — never a fabricated number.
     */
    public function kpiMetrics(PerformanceScopeRequest $request, BdKpiService $kpi): JsonResponse
    {
        // Scope resolved by PerformanceScopeRequest (default self/all; `?user_id` = one BD, Admin/SA only).
        // BdKpiService::forUser already computes M-01…M-06 for any subject via scopeVisibleTo — no new logic.
        $result = $kpi->forUser($request->scope()->subject);

        return response()->json(['data' => [
            'metrics' => array_map(fn (KpiMetric $m): array => [
                'id' => $m->id,
                'label' => $m->label,
                'unit' => $m->unit,
                'value' => $m->value,
                'evaluable' => $m->evaluable,
                'reason' => $m->reason,
                'components' => $m->components,
                'feeds' => $m->feeds,
            ], $result->metrics),
        ]]);
    }

    /**
     * BD Ranking (Interim) — the relative 0–100 index and ordinal rank across active BDs
     * (BD-PERF-COMP-SPEC §5, B-01). **Interim model** (R-10): the response always carries
     * `interim: true`, and consumers must label it Interim. Admin/Super-Admin only (route-guarded) —
     * a cross-BD ranking is a management view. `status` is `ranked` or `na` (fewer than the required
     * peers, §5.6). Each row exposes its raw components, peer-relative sub-scores, the BDR index, and
     * rank — the explainable breakdown; a BD with no portfolio is surfaced (unranked) with a reason,
     * never ranked last with 0.
     */
    public function bdRanking(BdRankingService $ranking): JsonResponse
    {
        $result = $ranking->rank();

        $round1 = fn (float $v): float => round($v, 1);

        return response()->json(['data' => [
            'interim' => $result->interim,
            'status' => $result->status,
            'model' => 'Interim Ranking Model',
            'rows' => array_map(fn (BdRankingRow $r): array => [
                'bdId' => $r->bdId,
                'name' => $r->name,
                'initials' => $r->initials,
                'ranked' => $r->ranked,
                'rank' => $r->rank,
                'bdr' => $r->bdr,
                'reason' => $r->reason,
                'raw' => [
                    'portfolioQuality' => $round1($r->raw['c1']),
                    'engagementCoverage' => $round1($r->raw['c2']),
                    'interestRate' => $round1($r->raw['c3']),
                    'activityVolume' => $r->raw['c4'],
                ],
                'subScores' => $r->subs === null ? null : [
                    'c1' => $round1($r->subs['c1']),
                    'c2' => $round1($r->subs['c2']),
                    'c3' => $round1($r->subs['c3']),
                    'c4' => $round1($r->subs['c4']),
                ],
            ], $result->rows),
        ]]);
    }

    /** First letters of the first two name parts, uppercased (e.g. "Jham Reyes" → "JR"). */
    private function initialsFor(string $name): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
        $letters = array_map(fn (string $p): string => mb_substr($p, 0, 1), array_slice($parts, 0, 2));

        return mb_strtoupper(implode('', $letters)) ?: '?';
    }
}
