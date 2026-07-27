<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Crm\Requests\AssignLeadRequest;
use App\Modules\Crm\Requests\ImportLeadsRequest;
use App\Modules\Crm\Requests\IndexArchivedLeadRequest;
use App\Modules\Crm\Requests\IndexLeadRequest;
use App\Modules\Crm\Requests\IngestDiscoveredLeadsRequest;
use App\Modules\Crm\Requests\RestoreAllRequest;
use App\Modules\Crm\Requests\StoreLeadRequest;
use App\Modules\Crm\Requests\UpdateLeadRequest;
use App\Modules\Crm\Resources\ActivityResource;
use App\Modules\Crm\Resources\ArchivedLeadResource;
use App\Modules\Crm\Resources\EmailResource;
use App\Modules\Crm\Resources\LeadResource;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Lead;
use App\Models\User;
use App\Modules\Crm\Services\LeadCreator;
use App\Modules\Crm\Services\LeadImportService;
use App\Modules\Crm\Services\Scoring\LeadScorer;
use App\Modules\Crm\Services\Scoring\ScoreSignal;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LeadController extends Controller
{
    public function index(IndexLeadRequest $request): AnonymousResourceCollection
    {
        // Sort whitelist → [column, direction]. Default ('recent'/absent) = newest first, i.e. the
        // prior `latest('id')` behavior, unchanged. Export reuses this same ordering (filter-aware).
        [$sortColumn, $sortDir] = match ($request->validated('sort')) {
            'oldest' => ['id', 'asc'],
            'score-desc' => ['score', 'desc'],
            'score-asc' => ['score', 'asc'],
            'name-asc' => ['business_name', 'asc'],
            'name-desc' => ['business_name', 'desc'],
            default => ['id', 'desc'],
        };

        // Active CRM leads only — discovered prospects live in the Nearby Clients pool and enter the
        // Leads list (and its export, which reuses this query) only once qualified.
        $query = Lead::query()
            ->active()
            ->visibleTo($request->user())
            ->with('assignedTo');

        // Shared with the Priority Summary cards (GET /leads/priority-summary): the table and the
        // cards filter over the IDENTICAL dataset so they can never disagree. Priority is applied
        // here (it is a real table filter / card drill-down).
        $this->applyLeadListFilters($query, $request, applyPriority: true);

        $leads = $query
            ->orderBy($sortColumn, $sortDir)
            // Stable tiebreaker so pagination is deterministic when the primary sort has ties
            // (id is monotonic with creation → also keeps just-imported leads grouped sensibly).
            ->when($sortColumn !== 'id', fn (Builder $sub) => $sub->orderByDesc('id'))
            ->paginate(10);

        // Real per-industry lead totals across the whole dataset — grouped in SQL, independent of
        // the current page/filters/selection — so the niche chips + distribution bar show accurate,
        // stable counts (industry IS the niche; no taxonomy/mapping). Returned alongside the list
        // in the same response (no extra endpoint). Soft-deleted leads are excluded via the model.
        $industryCounts = Lead::query()
            ->active()
            ->visibleTo($request->user())
            ->whereNotNull('industry')
            ->where('industry', '!=', '')
            ->groupBy('industry')
            ->orderBy('industry')
            ->selectRaw('industry, COUNT(*) as lead_count')
            ->pluck('lead_count', 'industry')
            ->map(static fn ($count): int => (int) $count)
            ->all();

        return LeadResource::collection($leads)->additional([
            'industry_counts' => $industryCounts,
        ]);
    }

    /**
     * Priority distribution for the Leads-page summary cards (High / Medium / Low counts).
     *
     * Aggregates `leads.priority` — the manual business-priority field — NOT Lead Score and NOT
     * website_status. The cards therefore always agree with the table's Priority column (both read
     * the same column). Ownership-scoped (visibleTo) and filter-aware: it honors every list filter
     * EXCEPT priority itself (see {@see applyLeadListFilters}), so the three cards reflect the same
     * dataset the table shows and act as a priority switcher over it. Priority is never derived
     * from Lead Score — a lead's score and its (manual) priority may intentionally differ.
     *
     * Footer metrics: avgScore = average Lead Score within the bucket (analytical only); totalPct =
     * the bucket's share of the filtered leads (% of total leads, not pipeline value).
     */
    public function prioritySummary(IndexLeadRequest $request): JsonResponse
    {
        $query = Lead::query()->active()->visibleTo($request->user());
        $this->applyLeadListFilters($query, $request, applyPriority: false);

        $total = (clone $query)->count();

        /** @var Collection<int, object{priority: string, count: int, avg_score: float|null}> $rows */
        $rows = (clone $query)
            ->selectRaw('priority, COUNT(*) as count, AVG(score) as avg_score')
            ->groupBy('priority')
            ->get();

        $buckets = collect(['high', 'medium', 'low'])->map(function (string $priority) use ($rows, $total): array {
            $row = $rows->firstWhere('priority', $priority);
            $count = (int) ($row->count ?? 0);

            return [
                'priority' => $priority,
                'count' => $count,
                'avgScore' => (int) round((float) ($row->avg_score ?? 0)),
                'totalPct' => $total > 0 ? (int) round($count / $total * 100) : 0,
            ];
        })->all();

        return response()->json(['data' => $buckets]);
    }

    /**
     * Lead Status summary for the Leads-page tabs (Total / New / Contacted / Qualified / Declined).
     *
     * Exists so the tabs can be computed from the SAME dataset the table is showing. The counts are
     * aggregated in SQL over {@see applyLeadListFilters} — the identical filter logic the list uses —
     * so selecting a BD (`assigned_to`) narrows the table and these counts together and the two can
     * never disagree. Ownership-scoped by `visibleTo`, exactly like the list.
     *
     * Every list filter is honoured EXCEPT `status` itself: the tabs ARE the status switcher, so
     * applying the active status would leave one tab holding the whole count and the rest at zero.
     * This mirrors {@see prioritySummary}, which excludes `priority` for the same reason.
     *
     * The response shape matches the `statusCounts` block the Leads tabs already consume, so the
     * frontend's existing tab→status mapping and counting are unchanged.
     */
    public function statusSummary(IndexLeadRequest $request): JsonResponse
    {
        $query = Lead::query()->active()->visibleTo($request->user());
        $this->applyLeadListFilters($query, $request, applyPriority: true, applyStatus: false);

        /** @var array<string, int> $counts */
        $counts = (clone $query)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->map(static fn ($count): int => (int) $count)
            ->all();

        // Every status is present (0 when absent) so a tab never renders a blank instead of a zero.
        $statusCounts = [];
        foreach (['new', 'interested', 'awaiting-reply', 'call-back-req', 'declined'] as $status) {
            $statusCounts[$status] = $counts[$status] ?? 0;
        }

        return response()->json(['data' => [
            // Total is the sum of the filtered dataset, NOT a global count — it is what the table's
            // own "Showing N of M" reports for the same filters.
            'totalLeads' => array_sum($counts),
            'statusCounts' => $statusCounts,
        ]]);
    }

    /**
     * Website Opportunity buckets — lead counts / average score / share per `website_status`.
     *
     * This is the SINGLE source for the "Lead Priority Overview" cards on BOTH the Dashboard (the
     * entry point) and the Leads page (the workspace), so the two can never drift apart again. It
     * lives here, alongside {@see prioritySummary}, specifically to reuse {@see applyLeadListFilters}
     * — the same filter logic the table uses — rather than duplicating it.
     *
     * Filter-aware: honours search / status / niche / priority so the workspace cards stay in step
     * with the table. `applyWebsiteStatus: false` mirrors the Priority Summary's own-dimension rule,
     * so drilling into one bucket never zeroes the other two.
     *
     * The Dashboard sends no filters, so its figures are identical to before this became
     * filter-aware — the denominator collapses to "all visible leads" exactly as it did.
     *
     * Route path is unchanged (`/dashboard/priority-buckets`); only the controller moved.
     */
    public function websiteStatusBuckets(IndexLeadRequest $request): JsonResponse
    {
        $query = Lead::query()->active()->visibleTo($request->user());
        $this->applyLeadListFilters($query, $request, applyPriority: true, applyWebsiteStatus: false);

        $total = (clone $query)->count();

        /** @var Collection<int, object{website_status: string, count: int, avg_score: float|null}> $rows */
        $rows = (clone $query)
            ->selectRaw('website_status, COUNT(*) as count, AVG(score) as avg_score')
            ->groupBy('website_status')
            ->get();

        // Always emit all three buckets in render order, zero-filled — a stable card layout.
        $buckets = collect(['no-website', 'needs-redesign', 'has-website'])
            ->map(function (string $websiteStatus) use ($rows, $total): array {
                $row = $rows->firstWhere('website_status', $websiteStatus);
                $count = (int) ($row->count ?? 0);

                return [
                    'websiteStatus' => $websiteStatus,
                    'count' => $count,
                    'avgScore' => (int) round((float) ($row->avg_score ?? 0)),
                    'pipelinePct' => $total > 0 ? (int) round($count / $total * 100) : 0,
                ];
            })->all();

        return response()->json(['data' => $buckets]);
    }

    /**
     * Apply the shared Leads list filters to a visibleTo-scoped query so the list endpoint and the
     * Priority Summary cards ({@see prioritySummary}) always operate on the EXACT same dataset —
     * the table and the cards must never disagree. `$applyPriority` is false for the summary so
     * that drilling the table into one priority (?priority=high) never zeroes the other two cards:
     * the cards always show the full priority distribution of the current non-priority filters.
     *
     * `$applyWebsiteStatus` is the same protection for the Website Opportunity cards
     * ({@see websiteStatusBuckets}): false there so drilling into one website bucket never zeroes
     * the other two. Defaults to true so the list endpoint keeps filtering on it as before.
     *
     * @param  EloquentBuilder<Lead>  $query
     */
    private function applyLeadListFilters(
        EloquentBuilder $query,
        IndexLeadRequest $request,
        bool $applyPriority,
        bool $applyWebsiteStatus = true,
        // Excluded by the status summary, so its tabs stay a switcher OVER the filtered dataset
        // rather than collapsing to the single status already selected — the same reason
        // prioritySummary excludes `priority`.
        bool $applyStatus = true,
    ): void {
        $search = trim((string) $request->validated('search'));
        $priority = $request->validated('priority');
        $websiteStatus = $request->validated('website_status');
        // Communication-lifecycle filters. Applied to every consumer of this method (list, Priority
        // Summary, Website Status buckets) so the cards keep filtering over the IDENTICAL dataset the
        // table shows — the same contract `status`, `search` and `industry` already follow.
        $emailOutreach = $request->validated('email_outreach');
        $coldCalling = $request->validated('cold_calling');
        // Ownership filter: the UNASSIGNED sentinel means `assigned_to IS NULL`, otherwise a user id.
        $assignedTo = (string) $request->validated('assigned_to');
        // Status may be a comma-separated list (e.g. a summary tab mapping to multiple statuses).
        $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $request->validated('status')))));
        // Raw industry filter — comma-separated free-text values (the frontend derives these from
        // the selected canonical niches). Compared case-insensitively so casing/imports don't matter.
        $industries = array_values(array_filter(array_map(
            static fn (string $v): string => mb_strtolower(trim($v)),
            explode(',', (string) $request->validated('industry')),
        )));

        $query
            ->when($search !== '', function (Builder $q) use ($search): void {
                // Match across business, contact, email, phone (MVP scope). LIKE wildcards escaped.
                $term = '%'.addcslashes($search, '%_\\').'%';
                $q->where(function (Builder $sub) use ($term): void {
                    $sub->where('business_name', 'like', $term)
                        ->orWhere('contact_name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->when($applyPriority && $priority, function (Builder $q) use ($priority): void {
                $q->where('priority', $priority);
            })
            ->when($applyWebsiteStatus && $websiteStatus, function (Builder $q) use ($websiteStatus): void {
                $q->where('website_status', $websiteStatus);
            })
            ->when($applyStatus && $statuses !== [], function (Builder $q) use ($statuses): void {
                $q->whereIn('status', $statuses);
            })
            ->when($emailOutreach, function (Builder $q) use ($emailOutreach): void {
                $q->where('email_outreach', $emailOutreach);
            })
            ->when($coldCalling, function (Builder $q) use ($coldCalling): void {
                $q->where('cold_calling', $coldCalling);
            })
            ->when($assignedTo === IndexLeadRequest::UNASSIGNED, function (Builder $q): void {
                $q->whereNull('assigned_to');
            })
            ->when($assignedTo !== '' && $assignedTo !== IndexLeadRequest::UNASSIGNED, function (Builder $q) use ($assignedTo): void {
                $q->where('assigned_to', $assignedTo);
            })
            ->when($industries !== [], function (Builder $q) use ($industries): void {
                $q->whereIn(DB::raw('LOWER(industry)'), $industries);
            });
    }

    public function store(StoreLeadRequest $request, LeadCreator $creator): JsonResponse
    {
        $data = $request->validated();

        // `note` is not a lead column — it is the optional first internal note, created alongside the
        // lead in one transaction by the service (which also owns the BD auto-assign rule).
        $initialNote = $data['note'] ?? null;
        unset($data['note']);

        $lead = $creator->create($data, $request->user(), $initialNote);

        return response()->json([
            'data' => new LeadResource($lead->load('assignedTo')),
        ], 201);
    }

    public function show(Lead $lead): JsonResponse
    {
        return response()->json([
            'data' => new LeadResource($lead->load('assignedTo')),
        ]);
    }

    public function update(UpdateLeadRequest $request, Lead $lead): JsonResponse
    {
        $lead->update($request->validated());

        return response()->json([
            'data' => new LeadResource($lead->load('assignedTo')),
        ]);
    }

    public function destroy(Lead $lead): JsonResponse
    {
        $lead->delete();

        return response()->json(null, 204);
    }

    // ── Archive Management (Admin/Super Admin) ──────────────────────────────────────────────────
    // Soft delete (`deleted_at`) is the single archive state — there is no separate archive table.
    // Archiving a lead is `destroy()` above (soft delete, logged by LeadObserver::deleted). The
    // methods below list archived leads and restore them.

    /**
     * List archived (soft-deleted) leads with the archive filter set (search / year on the archive
     * date / status / assigned BD / archived-by), newest-archived first, paginated. Ownership-scoped
     * via `Lead::scopeVisibleTo` (a pass-through for Admin/SA — the only roles the route allows).
     * The header count, the "Archived By" filter options, and the owning-BD are all backend-supplied.
     */
    public function archived(IndexArchivedLeadRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $leads = $this->archivedQuery($user, $request->validated())
            // assignedTo → owning BD; archiveActivity.causer → "Archived By" (eager-loaded, no N+1).
            ->with(['assignedTo', 'archiveActivity.causer'])
            ->orderByDesc('deleted_at')
            ->orderByDesc('id') // stable tiebreaker for deterministic pagination
            ->paginate(10);

        return ArchivedLeadResource::collection($leads)->additional([
            // Total archived leads in the user's scope, independent of the current filters — the
            // backend-backed header count ("N Archived Leads").
            'summary' => [
                'totalArchived' => Lead::onlyTrashed()->visibleTo($user)->count(),
            ],
            // Distinct users who have archived a visible lead — powers the "Archived By" filter
            // dropdown (backend-driven; no hardcoded names).
            'filters' => [
                'archivers' => $this->archiverOptions($user),
            ],
        ]);
    }

    /**
     * Restore ONE archived lead. The single restore workflow (see {@see restoreLead()}); the bulk
     * endpoint funnels through the same method, so restore logic and its Activity log exist once.
     * Route binding uses `withTrashed()` so the soft-deleted model resolves; authorized by
     * LeadPolicy@restore (leads.restore + ownership).
     */
    public function restore(Lead $lead): JsonResponse
    {
        $this->restoreLead($lead);

        return response()->json(['data' => ['id' => (string) $lead->id, 'restored' => true]]);
    }

    /**
     * Bulk restore. One endpoint, two archive UI actions: pass `ids` to restore the checked rows
     * ("Restore Selected"), or omit them to restore the whole current filter set ("Restore all
     * filtered"). Every matched lead is restored through the SAME per-record path as {@see restore()}
     * — one restore workflow, one Activity per lead — each individually authorized.
     */
    public function restoreAll(RestoreAllRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $query = $this->archivedQuery($user, $data);

        /** @var array<int, int> $ids */
        $ids = $data['ids'] ?? [];
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        $restored = 0;
        foreach ($query->get() as $lead) {
            // Per-record authorization: leads.restore + ownership (a pass-through for Admin/SA).
            $this->authorize('restore', $lead);
            $this->restoreLead($lead);
            $restored++;
        }

        return response()->json(['data' => ['restored' => $restored]]);
    }

    /**
     * The single restore mechanism. `$lead->restore()` clears `deleted_at` (the sole archive state)
     * and fires LeadObserver::restored(), which writes the one immutable `lead.restored` Activity.
     * Idempotent — a non-archived lead is left untouched.
     */
    private function restoreLead(Lead $lead): void
    {
        if ($lead->trashed()) {
            $lead->restore();
        }
    }

    /**
     * Shared archived-leads query (soft-deleted only, ownership-scoped) with the archive filters
     * applied. Used by BOTH {@see archived()} (list) and {@see restoreAll()} (bulk), so the archive
     * filter semantics are defined exactly once.
     *
     * @param  array<string, mixed>  $filters
     * @return EloquentBuilder<Lead>
     */
    private function archivedQuery(User $user, array $filters): EloquentBuilder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $statuses = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ($filters['status'] ?? '')),
        )));
        $year = $filters['year'] ?? null;
        $assignedBd = $filters['assigned_bd'] ?? null;
        $archivedBy = $filters['archived_by'] ?? null;

        return Lead::onlyTrashed()
            ->visibleTo($user)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function (Builder $sub) use ($term): void {
                    $sub->where('business_name', 'like', $term)
                        ->orWhere('contact_name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->when($statuses !== [], fn (Builder $query) => $query->whereIn('status', $statuses))
            ->when($year !== null && $year !== '', fn (Builder $query) => $query->whereYear('deleted_at', (int) $year))
            ->when($assignedBd === 'unassigned', fn (Builder $query) => $query->whereNull('assigned_to'))
            ->when($assignedBd !== null && $assignedBd !== '' && $assignedBd !== 'unassigned',
                fn (Builder $query) => $query->where('assigned_to', (int) $assignedBd))
            ->when($archivedBy !== null && $archivedBy !== '', function (Builder $query) use ($archivedBy): void {
                // Leads whose `deleted` Activity was caused by the given user (archived-by).
                $query->whereIn('id', Activity::query()
                    ->where('subject_type', (new Lead)->getMorphClass())
                    ->where('event', 'deleted')
                    ->where('causer_id', (int) $archivedBy)
                    ->select('subject_id'));
            });
    }

    /**
     * Distinct users who have archived a lead visible to the requester — the backend-driven option
     * set for the "Archived By" filter (no hardcoded names).
     *
     * @return array<int, array{id: string, name: string}>
     */
    private function archiverOptions(User $user): array
    {
        $causerIds = Activity::query()
            ->where('subject_type', (new Lead)->getMorphClass())
            ->where('event', 'deleted')
            ->whereNotNull('causer_id')
            ->whereIn('subject_id', Lead::onlyTrashed()->visibleTo($user)->select('id'))
            ->distinct()
            ->pluck('causer_id');

        return User::query()
            ->whereIn('id', $causerIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u): array => ['id' => (string) $u->id, 'name' => $u->name])
            ->all();
    }

    public function assign(AssignLeadRequest $request, Lead $lead): JsonResponse
    {
        $assignedTo = (int) $request->validated('assigned_to');

        $lead->assigned_to = $assignedTo;
        // saveQuietly() skips the generic "updated" Activity — a dedicated lead.assigned
        // event is recorded below (clean attribution for the future Management Dashboard).
        $lead->saveQuietly();
        $lead->load('assignedTo');

        $lead->activities()->create([
            'causer_id' => $request->user()->id,
            'event' => 'lead.assigned',
            'description' => sprintf(
                '%s assigned %s to %s',
                $request->user()->name,
                $lead->business_name,
                $lead->assignedTo->name,
            ),
            'properties' => [
                'assigned_to' => $assignedTo,
                'to_name' => $lead->assignedTo->name,
            ],
        ]);

        return response()->json([
            'data' => new LeadResource($lead),
        ]);
    }

    public function import(ImportLeadsRequest $request, LeadImportService $service): JsonResponse
    {
        $user = $request->user();
        // A BD's own import auto-assigns to the importing BD; Admin/SA use the selected BD.
        $assignedTo = $user->hasRole('crm_bd')
            ? $user->id
            : $request->validated('assigned_to');

        $result = $service->import(
            $request->file('file'),
            $user->id,
            $request->isDryRun(),
            $assignedTo !== null ? (int) $assignedTo : null,
        );

        return response()->json(['data' => $result]);
    }

    /**
     * Programmatic enrichment ingest — a discovery provider (Python Scraper, provider-agnostic)
     * submits a batch of normalized discovered businesses as JSON. Authorized by the same
     * `import` permission as file import. Lands fit-data only (never engagement fields), assigns
     * no owner, and dedups by place_id / email so re-discovery is idempotent.
     */
    public function ingestDiscovered(IngestDiscoveredLeadsRequest $request, LeadImportService $service): JsonResponse
    {
        $result = $service->ingestDiscovered(
            $request->validated('businesses'),
            $request->user()->id,
            $request->boolean('dry_run'),
        );

        return response()->json(['data' => $result]);
    }

    public function activities(Lead $lead): AnonymousResourceCollection
    {
        $activities = $lead->activities()
            ->with('causer')
            ->latest()
            ->paginate(15);

        return ActivityResource::collection($activities);
    }

    /**
     * The full email conversation with this lead (outbound + inbound), chronologically ordered — the
     * read-only source for the Lead Details "Email Thread" panel. Reuses the existing Email model +
     * EmailResource (no duplicated mapping) and eager-loads the sender to avoid N+1. Ownership is
     * enforced by the route (`can:view,lead`); the relation carries the chronological order.
     */
    public function emails(Lead $lead): AnonymousResourceCollection
    {
        $emails = $lead->emails()
            ->with('sender')
            ->paginate(20);

        return EmailResource::collection($emails);
    }

    /**
     * Personal Lead Score breakdown for this lead — the auditable "why" behind `leads.score`
     * (BD-PERF-COMP-SPEC R-3) and the data source for the Lead Details "Score Breakdown" panel.
     * Computes live from the frozen signal model so the response always matches the stored score's
     * rules; ownership is enforced by the route (`can:view,lead`).
     *
     * Shape is panel-ready: a `categories` array (Fit / Engagement / Negative) — each with its
     * subtotal, cap, and the signals under it — plus the clamped `final` score. `signals` is also
     * returned flat for consumers that prefer a single list.
     */
    public function score(Lead $lead, LeadScorer $scorer): JsonResponse
    {
        $result = $scorer->calculate($lead);

        $toArray = fn (ScoreSignal $s): array => [
            'key' => $s->key,
            'label' => $s->label,
            'category' => $s->category,
            'points' => $s->points,
            'applied' => $s->applied,
            'evaluable' => $s->evaluable,
        ];
        $inCategory = fn (string $category): array => array_values(array_map(
            $toArray,
            array_filter($result->signals, fn (ScoreSignal $s): bool => $s->category === $category),
        ));

        return response()->json(['data' => [
            'leadId' => (string) $lead->id,
            'final' => [
                'score' => $result->score,
                'max' => $result->scoreMax,
            ],
            'categories' => [
                [
                    'key' => ScoreSignal::FIT,
                    'label' => 'Fit',
                    'subtotal' => $result->fit,
                    'max' => $result->fitMax,
                    'signals' => $inCategory(ScoreSignal::FIT),
                ],
                [
                    'key' => ScoreSignal::ENGAGEMENT,
                    'label' => 'Engagement',
                    'subtotal' => $result->engagement,
                    'max' => $result->engagementMax,
                    'signals' => $inCategory(ScoreSignal::ENGAGEMENT),
                ],
                [
                    'key' => ScoreSignal::NEGATIVE,
                    'label' => 'Negative',
                    'subtotal' => $result->negative, // positive magnitude; subtracted from the total
                    'max' => null,
                    'signals' => $inCategory(ScoreSignal::NEGATIVE),
                ],
            ],
            // Flat list (category on each) for consumers that don't want the grouped shape.
            'signals' => array_map($toArray, $result->signals),
        ]]);
    }
}
