<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Jobs\RunDiscoveryJob;
use App\Modules\Crm\Models\DiscoveryRun;
use App\Modules\Crm\Models\DiscoveryRunTarget;
use App\Modules\Crm\Requests\StoreDiscoveryRunRequest;
use App\Modules\Crm\Resources\DiscoveryRunResource;
use App\Support\CurrentOrg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * On-demand discovery runs — the Stage 2 execution surface.
 *
 * ADDITIVE. `GET /nearby-clients` and everything it feeds are untouched: the prospect pool, its
 * filters, its map and its empty states behave exactly as they did before this controller existed.
 * Opening Nearby Clients still performs one pool fetch and starts nothing. Discovery happens only
 * when a user explicitly asks for it, here.
 *
 * AUTHORIZATION IS BORROWED, NEVER INVENTED. The routes reuse the existing paid-capability gate —
 * `can('import', Lead::class)` plus `role:super_admin|crm_admin` — the same pair already guarding
 * `/discovery/places`, `/discovery/proposals`, `/discovery/strategy` and `/discovery/tag-expansions`,
 * for the same documented reason: `crm.leads.import` is deliberately held by BD managers for their
 * own spreadsheet imports, so permission alone would let any BD run up a provider bill. No existing
 * role, policy, permission or middleware is modified, added to or weakened by this feature.
 *
 * The request never waits for the run. It returns 202 with a run id in milliseconds and the work
 * happens on the `discovery` queue, because a nationwide sweep cannot be held open by an HTTP
 * connection.
 */
class DiscoveryRunController extends Controller
{
    /** Start a run, or hand back the one this click already started. */
    public function store(StoreDiscoveryRunRequest $request): JsonResponse
    {
        $orgId = CurrentOrg::id();
        $userId = (int) $request->user()->id;
        $key = $request->string('idempotency_key')->value() ?: null;

        // A double-click, a refresh and a network retry are the SAME intent. Returning the original
        // run (200, not 202) is what stops one click becoming two paid sweeps.
        if ($key !== null) {
            $existing = DiscoveryRun::query()->where('idempotency_key', $key)->first();

            if ($existing !== null) {
                return response()->json(
                    ['data' => new DiscoveryRunResource($existing->load('targets'))],
                    Response::HTTP_OK,
                );
            }
        }

        $niches = $this->clean($request->input('niches', []));
        $country = $request->country();

        if ($request->isNationwide()) {
            return $this->startNationwide($request, $niches, $country, $userId, $key, (int) $orgId);
        }

        $locations = $this->clean($request->input('locations', []));
        $limit = (int) $request->input('limit_per_task', config('discovery.default_limit_per_task', 20));

        if ($locations === [] || $niches === []) {
            return response()->json(['message' => 'Give at least one location and one business niche.'], 422);
        }

        // Every (location, niche) combination is one search. The cross product is deliberate: "all
        // niches" must mean all of them, not whichever subset some curated file happens to pair with
        // that city.
        $pairs = [];
        foreach ($locations as $area) {
            foreach ($niches as $niche) {
                $pairs[] = [$area, $niche];
            }
        }

        if (($conflict = $this->activeConflict($pairs)) !== null) {
            return response()->json([
                'message' => 'A discovery run covering some of these searches is already in progress.',
                'data' => ['runId' => $conflict],
            ], Response::HTTP_CONFLICT);
        }

        $ai = $request->wantsAiStrategy();

        $run = DB::transaction(function () use ($pairs, $locations, $niches, $limit, $country, $userId, $key, $ai): DiscoveryRun {
            $run = DiscoveryRun::query()->create([
                'scope_class' => DiscoveryRun::SCOPE_SCOPED,
                'status' => DiscoveryRun::STATUS_PENDING,
                // `country` restricts the engine's geocoder, so a location outside it cannot be
                // resolved and therefore cannot be searched. Validated against the supported list.
                'scope' => [
                    'locations' => $locations,
                    'niches' => $niches,
                    'limitPerTask' => $limit,
                    'country' => $country,
                ],
                'requested_by' => $userId,
                'idempotency_key' => $key,
                'targets_total' => count($pairs),
                // Per-run opt-in, already checked against the live capability by the request.
                'ai_strategy_requested' => $ai,
            ]);

            foreach ($pairs as [$area, $niche]) {
                $run->targets()->create([
                    'area' => $area,
                    'category' => $niche,
                    'limit_per_task' => $limit,
                    'status' => DiscoveryRunTarget::STATUS_PENDING,
                ]);
            }

            return $run;
        });

        // ->onQueue('discovery') is load-bearing: the dedicated worker consumes that queue and no
        // other. Omitting it would enqueue this run where nothing is listening.
        RunDiscoveryJob::dispatch($run->uuid, (int) $orgId)->onQueue('discovery');

        return response()->json(
            ['data' => new DiscoveryRunResource($run->load('targets'))],
            Response::HTTP_ACCEPTED,
        );
    }

    /**
     * Start a nationwide run: the whole country, until the provider runs out or the budget does.
     *
     * NO QUOTA IS SET HERE BECAUSE THERE IS NO QUOTA. The run asks the provider for everything it
     * will give across every cell of the national polygon; what bounds it is the safety budget and
     * the provider's own willingness to answer, never a per-city ceiling.
     *
     * A DIFFERENT GENERATION PATH, NOT A BIGGER BUTTON. Advanced Lead Discovery searches places a
     * user named, at its own default of 20 results per search, and none of that changes here — the
     * numbers below come from `discovery.nationwide.*`, a separate block of configuration, precisely
     * so tuning one can never move the other.
     *
     * NOTHING IS PLANNED IN THIS REQUEST. The country's cells fall out of thousands of geometric
     * containment tests against the national polygon, which belongs on the queue, not on an HTTP
     * connection. So this endpoint records the INTENT — the niches, the budget, the per-area target —
     * and returns in milliseconds; the orchestrator derives the areas and reports them as it goes.
     * Until it does, the run honestly shows zero areas and a `planning` phase.
     *
     * @param  list<string>  $niches
     */
    private function startNationwide(
        StoreDiscoveryRunRequest $request,
        array $niches,
        string $country,
        int $userId,
        ?string $key,
        int $orgId,
    ): JsonResponse {
        if ($niches === []) {
            return response()->json(['message' => 'Give at least one business niche to search for.'], 422);
        }

        // ONE NATIONWIDE RUN AT A TIME. The scoped path refuses only an exact (area, niche) overlap,
        // because two genuinely different scopes may legitimately run together. Two nationwide runs
        // have no "different scope" available to them — they cover the same country — so a second one
        // is always a duplicate purchase of the first.
        $active = DiscoveryRun::query()
            ->where('scope_class', DiscoveryRun::SCOPE_NATIONWIDE)
            ->whereIn('status', [DiscoveryRun::STATUS_PENDING, DiscoveryRun::STATUS_RUNNING])
            ->first();

        if ($active !== null) {
            return response()->json([
                'message' => 'A nationwide discovery run is already in progress.',
                'data' => ['runId' => $active->uuid],
            ], Response::HTTP_CONFLICT);
        }

        // The DEFAULT is what one click costs; the CEILING is the most anyone may spend at once.
        // They are separate numbers because collapsing them made a full-country run unrequestable.
        $budget = min(
            (int) $request->input('max_tasks', config('discovery.nationwide.max_tasks', 400)),
            (int) config('discovery.nationwide.max_tasks_ceiling', 25000),
        );
        $cellDegrees = (float) $request->input(
            'cell_degrees',
            config('discovery.nationwide.cell_degrees', 0.25),
        );
        $limit = (int) config('discovery.nationwide.limit_per_task', 20);

        $run = DiscoveryRun::query()->create([
            'scope_class' => DiscoveryRun::SCOPE_NATIONWIDE,
            'status' => DiscoveryRun::STATUS_PENDING,
            // `locations` is present and EMPTY on purpose: the shape stays the one every consumer
            // already reads, and an empty list is the truthful answer to "which places did the user
            // name?" for a run whose scope is the country.
            'scope' => [
                'locations' => [],
                'niches' => $niches,
                'limitPerTask' => $limit,
                'cellDegrees' => $cellDegrees,
                'country' => $country,
            ],
            'requested_by' => $userId,
            'idempotency_key' => $key,
            // Both are discovered by the orchestrator, not knowable here. See the method docblock.
            'targets_total' => 0,
            'cells_planned' => 0,
            'budget_total' => $budget,
            'ai_strategy_requested' => $request->wantsAiStrategy(),
        ]);

        RunDiscoveryJob::dispatch($run->uuid, $orgId)->onQueue('discovery');

        return response()->json(
            ['data' => new DiscoveryRunResource($run->load('targets'))],
            Response::HTTP_ACCEPTED,
        );
    }

    /** Poll one run. Scoped to the caller's org by the model's global scope — a foreign id is a 404. */
    public function show(DiscoveryRun $discoveryRun): JsonResponse
    {
        // A nationwide run can accumulate hundreds of cells, so the per-search list it returns is
        // capped at the most recent few; the per-area SUMMARY (aggregated in SQL) is what conveys
        // the shape of the run. A scoped run keeps returning every one of its searches, unchanged.
        $discoveryRun->load(['targets' => fn ($q) => $discoveryRun->isNationwide()
            ? $q->orderByDesc('id')->limit(25)
            : $q->orderBy('id')]);

        return response()->json(['data' => new DiscoveryRunResource($discoveryRun)]);
    }

    /**
     * The runs still in flight.
     *
     * This is what lets a browser refresh re-attach to a run rather than orphan it — and what stops
     * a user starting a second sweep because the first became invisible.
     */
    public function index(Request $request): JsonResponse
    {
        $runs = DiscoveryRun::query()
            ->whereIn('status', [DiscoveryRun::STATUS_PENDING, DiscoveryRun::STATUS_RUNNING])
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return response()->json(['data' => DiscoveryRunResource::collection($runs)]);
    }

    /**
     * Stop a run between searches.
     *
     * Honest about its limits: this prevents the NEXT provider call, it cannot refund one already
     * made, and everything already discovered stays in the CRM.
     */
    public function cancel(DiscoveryRun $discoveryRun): JsonResponse
    {
        if ($discoveryRun->isTerminal()) {
            return response()->json([
                'message' => 'That run has already finished.',
                'data' => new DiscoveryRunResource($discoveryRun),
            ], Response::HTTP_CONFLICT);
        }

        $discoveryRun->forceFill(['status' => DiscoveryRun::STATUS_CANCELLED])->save();

        return response()->json(['data' => new DiscoveryRunResource($discoveryRun->load('targets'))]);
    }

    /**
     * Is any of this already being searched right now?
     *
     * Deliberately NOT a global lock: two genuinely different scopes may run at once. Only an actual
     * overlap — the same area and the same niche — is refused, which is the case where a second run
     * would spend money to rediscover what the first is already finding.
     *
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    private function activeConflict(array $pairs): ?string
    {
        $active = DiscoveryRun::query()
            ->whereIn('status', [DiscoveryRun::STATUS_PENDING, DiscoveryRun::STATUS_RUNNING])
            ->with('targets:id,discovery_run_id,area,category')
            ->get();

        $wanted = [];
        foreach ($pairs as [$area, $niche]) {
            $wanted[mb_strtolower($area).'|'.mb_strtolower($niche)] = true;
        }

        foreach ($active as $run) {
            foreach ($run->targets as $target) {
                $key = mb_strtolower($target->area).'|'.mb_strtolower($target->category);
                if (isset($wanted[$key])) {
                    return $run->uuid;
                }
            }
        }

        return null;
    }

    /**
     * Trim, drop blanks, de-duplicate case-insensitively, and re-index.
     *
     * Note what is NOT here: no whitelist, no canonicalization, no mapping to a known vocabulary.
     * "vape shop" and "Roxas City" are as valid as "pharmacy" and "Makati City".
     *
     * @return list<string>
     */
    private function clean(mixed $values): array
    {
        $seen = [];
        foreach ((array) $values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }

            $seen[mb_strtolower($trimmed)] ??= $trimmed;
        }

        return array_values($seen);
    }
}
