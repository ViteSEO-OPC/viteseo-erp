<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Crm\Requests\AssignLeadRequest;
use App\Modules\Crm\Requests\ImportLeadsRequest;
use App\Modules\Crm\Requests\DestroyAllLeadsRequest;
use App\Modules\Crm\Requests\IndexArchivedLeadRequest;
use App\Modules\Crm\Requests\IndexLeadRequest;
use App\Modules\Crm\Requests\IngestDiscoveredLeadsRequest;
use App\Modules\Crm\Requests\RestoreAllRequest;
use App\Modules\Crm\Requests\StoreLeadRequest;
use App\Modules\Crm\Requests\UpdateLeadRequest;
use App\Modules\Crm\Events\LeadAssigned;
// Platform-level fire-and-forget broadcast helper (App\Support), shared by every module.
use App\Support\SafeBroadcast;
use App\Modules\Crm\Resources\ActivityResource;
use App\Modules\Crm\Resources\ArchivedLeadResource;
use App\Modules\Crm\Resources\EmailResource;
use App\Modules\Crm\Resources\LeadResource;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Notifications\LeadAssignedNotification;
use App\Models\User;
use App\Modules\Crm\Services\LeadCreator;
use App\Modules\Crm\Jobs\ConvertXlsImportJob;
use App\Modules\Crm\Services\Import\XlsConverter;
use App\Modules\Crm\Services\Import\XlsImportJobStore;
use App\Modules\Crm\Services\LeadAssignmentService;
use App\Modules\Crm\Services\LeadImportService;
use Illuminate\Http\UploadedFile;
use App\Modules\Crm\Services\LeadPdfExporter;
use App\Modules\Crm\Services\Planner\FollowUpScheduler;
use App\Modules\Crm\Services\Planner\PlannerOwnershipTransfer;
use App\Modules\Crm\Services\Scoring\LeadScorer;
use App\Modules\Crm\Services\Scoring\ScoreSignal;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class LeadController extends Controller
{
    public function index(IndexLeadRequest $request): AnonymousResourceCollection
    {
        // Sort whitelist → [column, direction]. Export reuses this same ordering (filter-aware).
        //
        // `recent` (the default) means MOST RECENTLY ENTERED THE CRM — not the highest row id. The two
        // are the same thing only for leads created directly here. A discovered prospect is inserted
        // into the Nearby pool the day it is scraped and can sit there for months, so its id records
        // DISCOVERY time, not the moment a BD accepted it. Ordering by id therefore filed a prospect
        // accepted today onto whatever page its months-old id happened to land on, which is why
        // "Accept into CRM" looked like it had silently done nothing.
        //
        // COALESCE picks each lead's actual crossing point into the CRM: `qualified_at` for an accepted
        // prospect, `created_at` for a lead created or imported directly. The latter never carry a
        // `qualified_at`, so their relative order is unchanged — this only moves accepted prospects,
        // to where the BD who just accepted them expects to find them.
        $enteredCrmAt = DB::raw('COALESCE(qualified_at, created_at)');

        [$sortColumn, $sortDir] = match ($request->validated('sort')) {
            'oldest' => ['id', 'asc'],
            'score-desc' => ['score', 'desc'],
            'score-asc' => ['score', 'asc'],
            'name-asc' => ['business_name', 'asc'],
            'name-desc' => ['business_name', 'desc'],
            default => [$enteredCrmAt, 'desc'],
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
            // Load-bearing for the default sort too: a bulk import gives every row the same
            // `created_at` to the second, so without this their page order would be arbitrary.
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

        // Always emit every bucket in render order, zero-filled — a stable card layout. `website-unknown`
        // and `website-unreachable` are included because `$total` counts them: omitting them would leave
        // `pipelinePct` silently summing to less than 100% once unverified leads exist.
        $buckets = collect(['no-website', 'needs-redesign', 'has-website', 'website-unknown', 'website-unreachable'])
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

    /**
     * One lead as a PDF profile.
     *
     * The SINGLE export implementation — the Lead Details header action and the BD table's row action
     * both call this route, so the two can never drift. Authorized by `view` on the lead (the route's
     * `can('view', 'lead')`), which is the same ownership boundary that governs reading the lead at
     * all: if a user may open the lead, they may export it.
     */
    public function exportPdf(Lead $lead, LeadPdfExporter $exporter): SymfonyResponse
    {
        return $exporter->render($lead)->download($exporter->filename($lead));
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
        // Both outcome signals are resolved HERE ONLY (not in index/store/update/assign), so the list
        // keeps its exact current payload and query count. `deals` is eager-loaded because the Won
        // badge shows the amount and date; Lost needs only a boolean, so it is an EXISTS subquery
        // rather than a second relation load.
        $lead->load(['assignedTo', 'deals'])
            ->loadExists(['activities as is_lost' => fn ($query) => $query->where('event', 'lead.lost')]);

        return response()->json([
            'data' => new LeadResource($lead),
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
        $this->archiveLead($lead);

        return response()->json(null, 204);
    }

    /**
     * How many leads "Archive all leads" would affect, for the confirmation dialog.
     *
     * Shares {@see deletableQuery()} with {@see destroyAll()} so the number shown and the number
     * acted on are produced by the same scope — a count computed any other way could disagree with
     * the action, which on a destructive confirmation is worse than showing no number at all.
     */
    public function deletableCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['count' => $this->deletableQuery($request->user())->count()],
        ]);
    }

    /**
     * Archive EVERY lead in the caller's authorized scope — not merely the page they are looking at.
     *
     * Deliberately the same mechanism as archiving one lead: {@see archiveLead()} soft-deletes, which
     * is the single archive state, so every lead lands in the existing Archive, keeps its
     * `lead.archived` Activity from LeadObserver, and is restorable through the existing
     * restore/restoreAll path. There is no second deletion system and nothing here force-deletes.
     *
     * Three fences, none of which is the frontend:
     *   1. the route  — role:super_admin|crm_admin + permission:crm.leads.delete (a BD holds no
     *      `crm.leads.delete` at all, so this is refused before the controller runs);
     *   2. the query  — Lead's BelongsToOrg global scope confines it to the current tenant, and
     *      `visibleTo` applies the ownership boundary on top;
     *   3. per record — LeadPolicy@delete is re-asked for every lead, exactly as restoreAll does,
     *      so scope and policy must BOTH agree before anything is archived.
     *
     * Ids are collected before the loop rather than chunked over the live query: soft-deleting
     * removes a row from the default (non-trashed) query, so a cursor walking that query while it
     * shrinks would skip roughly every other row.
     */
    public function destroyAll(DestroyAllLeadsRequest $request): JsonResponse
    {
        $user = $request->user();

        /** @var \Illuminate\Support\Collection<int, int> $ids */
        $ids = $this->deletableQuery($user)->pluck('id');

        $archived = 0;
        foreach ($ids->chunk(200) as $chunk) {
            foreach ($this->deletableQuery($user)->whereIn('id', $chunk->all())->get() as $lead) {
                $this->authorize('delete', $lead);
                $this->archiveLead($lead);
                $archived++;
            }
        }

        return response()->json(['data' => ['archived' => $archived]]);
    }

    /**
     * The leads "Archive all leads" may touch: live (non-archived) leads inside the caller's scope.
     *
     * `Lead::query()` already excludes soft-deleted rows and is org-scoped by the BelongsToOrg global
     * scope; `visibleTo` narrows further to the ownership boundary (a pass-through for Admin/SA).
     * Defined once and shared by the count and the action.
     *
     * @return EloquentBuilder<Lead>
     */
    private function deletableQuery(User $user): EloquentBuilder
    {
        return Lead::query()->visibleTo($user);
    }

    /**
     * The single archive mechanism, mirroring {@see restoreLead()} on the other side.
     *
     * `$lead->delete()` sets `deleted_at` — the sole archive state, there is no archive table — and
     * fires LeadObserver::deleted, which writes the one immutable archive Activity. Idempotent: an
     * already-archived lead is left alone, so a repeated bulk run cannot double-log.
     */
    private function archiveLead(Lead $lead): void
    {
        if (! $lead->trashed()) {
            $lead->delete();
        }
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

    public function assign(
        AssignLeadRequest $request,
        Lead $lead,
        LeadAssignmentService $assignment,
    ): JsonResponse {
        // Null is a legitimate value here — it clears the owner. Casting unconditionally would
        // turn that into user 0 and silently fail the foreign key.
        $assignedTo = $request->validated('assigned_to');
        $assignedTo = $assignedTo === null ? null : (int) $assignedTo;

        // The handover itself now lives in LeadAssignmentService, shared with the resignation
        // handover in UserController so a bulk move cannot become a second ownership model with no
        // Activity trail and no Planner transfer. notify() and the broadcast stay HERE, after the
        // commit — see the note below on why their order is load-bearing, and so neither can fire
        // for a handover that rolled back.
        $assignment->assign($lead, $assignedTo, $request->user());

        // The durable record of the handover, for the new owner's Notification Center.
        // notify() is the only entry point: persisting fires NotificationSent, and
        // BroadcastCrmNotification pushes it to their bell — the same one path every
        // CRM notification takes. Nothing here broadcasts.
        //
        // ORDER MATTERS, and it is the reason this is not a listener on LeadAssigned.
        // Illuminate\Events\Dispatcher broadcasts BEFORE it runs listeners, and
        // SafeBroadcast deliberately swallows a Reverb failure — so a listener hung off
        // that event would silently not run during an outage, and the assignment would
        // reach nobody. Persisting first makes the record independent of the transport,
        // which is the whole reason it is persisted.
        // Only when the lead HAS a new owner. Clearing an assignment has nobody to notify, and
        // `$lead->assignedTo` is null — the previous owner is deliberately not told either: this
        // endpoint has never notified the outgoing BD on a reassignment, and an unassignment is a
        // reassignment. Nothing about that changed here.
        $lead->assignedTo?->notify(new LeadAssignedNotification($lead, $request->user()));

        // Realtime push — only after the write and its activity have succeeded, so a subscriber is
        // never told about an assignment that didn't land. toOthers() excludes the assigning client
        // (identified by its X-Socket-Id header) so the actor keeps its own optimistic update.
        // SafeBroadcast keeps a Reverb outage from turning this 200 into a 500.
        //
        // Still separate from the notification above, and still worth having: this is the
        // transient toast for a BD who is looking right now, and it also updates the lead's
        // own stream for Admins watching it. The notification is the record; this is the nudge.
        // Skipped when the owner was cleared: LeadAssigned broadcasts on `user.{assignedToId}`,
        // which with no owner is the meaningless channel `user.` — a push addressed to nobody. The
        // Activity above is still written, so the change remains fully audited, and the acting
        // client refetches on success regardless.
        if ($lead->assigned_to !== null) {
            SafeBroadcast::toOthers(new LeadAssigned($lead, $request->user()));
        }

        return response()->json([
            'data' => new LeadResource($lead),
        ]);
    }

    public function import(
        ImportLeadsRequest $request,
        LeadImportService $service,
        XlsConverter $xls,
        XlsImportJobStore $jobs,
    ): JsonResponse {
        $user = $request->user();
        // A BD's own import auto-assigns to the importing BD; Admin/SA use the selected BD.
        // UNCHANGED — every path below funnels through this one assignment decision.
        $assignedTo = $user->hasRole('crm_bd')
            ? $user->id
            : $request->validated('assigned_to');
        $assignedTo = $assignedTo !== null ? (int) $assignedTo : null;

        // ── Commit of an already-converted large `.xls` ────────────────────────────────────────
        // The workbook was converted once by the queue; the client sends the job id instead of
        // re-uploading it. Ownership, readiness and single-use are all enforced by the store.
        if (($jobId = $request->validated('job_id')) !== null) {
            return $this->importFromJob((string) $jobId, $request, $service, $jobs, $assignedTo);
        }

        $file = $request->file('file');

        // ── Large `.xls` → queue ───────────────────────────────────────────────────────────────
        // Measured BEFORE the workbook is materialized: listWorksheetInfo() reads dimension records
        // only, so this is safe on a 10 MB file inside a 128 MB worker. Only the dry-run is routed;
        // a commit always arrives with a job id and is handled above.
        if (strtolower($file->getClientOriginalExtension()) === 'xls'
            && $xls->estimateCells((string) $file->getRealPath()) > XlsConverter::SYNC_CELL_THRESHOLD) {
            return $this->queueXlsConversion($file, $user->id, $assignedTo, $jobs);
        }

        // ── Everything else: unchanged synchronous path ────────────────────────────────────────
        // `.xlsx`, CSV, TXT and small `.xls` all land here exactly as before.
        $result = $service->import($file, $user->id, $request->isDryRun(), $assignedTo);

        return response()->json(['data' => $result]);
    }

    /**
     * Hand a large `.xls` to the queue and return 202 with the job id to poll.
     *
     * The upload is moved out of PHP's request-scoped temp area first: that file is deleted the
     * moment the response is sent, and the worker needs it afterwards.
     */
    private function queueXlsConversion(
        UploadedFile $file,
        int $userId,
        ?int $assignedTo,
        XlsImportJobStore $jobs,
    ): JsonResponse {
        $jobId = $jobs->create($userId, (string) $file->getClientOriginalName());
        $source = $jobs->storagePathFor($jobId).'.source.xls';
        $file->move(dirname($source), basename($source));
        $jobs->attachSource($jobId, $source);

        ConvertXlsImportJob::dispatch(
            $jobId,
            $source,
            (string) $file->getClientOriginalName(),
            $userId,
            $assignedTo,
        );

        return response()->json([
            'data' => ['jobId' => $jobId, 'status' => XlsImportJobStore::STATUS_PENDING],
        ], 202);
    }

    /**
     * Commit a queued conversion by job id — the workbook is converted ONCE, never twice.
     *
     * A missing, foreign, unready or already-committed job all resolve to 404/422 here; the store
     * makes ownership indistinguishable from non-existence so ids cannot be enumerated. The claim
     * is a compare-and-set, so two concurrent confirms cannot both import.
     */
    private function importFromJob(
        string $jobId,
        ImportLeadsRequest $request,
        LeadImportService $service,
        XlsImportJobStore $jobs,
        ?int $assignedTo,
    ): JsonResponse {
        $userId = (int) $request->user()->id;
        $record = $jobs->forUser($jobId, $userId);

        if ($record === null) {
            return response()->json(['message' => 'Import job not found.'], 404);
        }

        // A dry-run against a finished job just replays the stored preview — no re-conversion.
        if ($request->isDryRun()) {
            if (($record['status'] ?? null) !== XlsImportJobStore::STATUS_READY) {
                return response()->json(['message' => 'This import is still being prepared.'], 422);
            }

            return response()->json(['data' => $record['preview']]);
        }

        // Single-use claim. Returns false for not-ready AND for already-committed.
        if (! $jobs->markCommitted($jobId, $userId)) {
            return response()->json([
                'message' => 'This import has already been completed or is no longer available.',
            ], 422);
        }

        try {
            $result = $service->import(
                new UploadedFile((string) $record['path'], (string) $record['original_name'], null, null, true),
                $userId,
                dryRun: false,
                assignedTo: $assignedTo,
                preConvertedPath: (string) $record['path'],
            );
        } finally {
            // Converted file removed on success AND on failure — it is single-use either way.
            $jobs->forget($jobId);
        }

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
