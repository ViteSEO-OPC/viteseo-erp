<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Crm\Requests\AssignLeadRequest;
use App\Modules\Crm\Requests\ImportLeadsRequest;
use App\Modules\Crm\Requests\IndexLeadRequest;
use App\Modules\Crm\Requests\IngestDiscoveredLeadsRequest;
use App\Modules\Crm\Requests\StoreLeadRequest;
use App\Modules\Crm\Requests\UpdateLeadRequest;
use App\Modules\Crm\Resources\ActivityResource;
use App\Modules\Crm\Resources\LeadResource;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\LeadImportService;
use App\Modules\Crm\Services\Scoring\LeadScorer;
use App\Modules\Crm\Services\Scoring\ScoreSignal;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class LeadController extends Controller
{
    public function index(IndexLeadRequest $request): AnonymousResourceCollection
    {
        $search = trim((string) $request->validated('search'));
        $priority = $request->validated('priority');
        $websiteStatus = $request->validated('website_status');
        // Status may be a comma-separated list (e.g. a summary tab mapping to multiple statuses).
        $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $request->validated('status')))));
        // Raw industry filter — comma-separated free-text values (the frontend derives these from
        // the selected canonical niches). Compared case-insensitively so casing/imports don't matter.
        $industries = array_values(array_filter(array_map(
            static fn (string $v): string => mb_strtolower(trim($v)),
            explode(',', (string) $request->validated('industry')),
        )));

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

        $leads = Lead::query()
            ->visibleTo($request->user())
            ->with('assignedTo')
            ->when($search !== '', function (Builder $query) use ($search): void {
                // Match across business, contact, email, phone (MVP scope). LIKE wildcards escaped.
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function (Builder $sub) use ($term): void {
                    $sub->where('business_name', 'like', $term)
                        ->orWhere('contact_name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->when($priority, function (Builder $query) use ($priority): void {
                $query->where('priority', $priority);
            })
            ->when($websiteStatus, function (Builder $query) use ($websiteStatus): void {
                $query->where('website_status', $websiteStatus);
            })
            ->when($statuses !== [], function (Builder $query) use ($statuses): void {
                $query->whereIn('status', $statuses);
            })
            ->when($industries !== [], function (Builder $query) use ($industries): void {
                $query->whereIn(DB::raw('LOWER(industry)'), $industries);
            })
            ->orderBy($sortColumn, $sortDir)
            // Stable tiebreaker so pagination is deterministic when the primary sort has ties
            // (id is monotonic with creation → also keeps just-imported leads grouped sensibly).
            ->when($sortColumn !== 'id', fn (Builder $query) => $query->orderByDesc('id'))
            ->paginate(10);

        // Real per-industry lead totals across the whole dataset — grouped in SQL, independent of
        // the current page/filters/selection — so the niche chips + distribution bar show accurate,
        // stable counts (industry IS the niche; no taxonomy/mapping). Returned alongside the list
        // in the same response (no extra endpoint). Soft-deleted leads are excluded via the model.
        $industryCounts = Lead::query()
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

    public function store(StoreLeadRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        // BD-created leads auto-assign to the creator (BDs own their own leads); Admin/SA must
        // select the owning BD (enforced by StoreLeadRequest).
        if ($user->hasRole('crm_bd')) {
            $data['assigned_to'] = $user->id;
        }

        $lead = Lead::create([
            ...$data,
            'created_by' => $user->id,
        ]);

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
