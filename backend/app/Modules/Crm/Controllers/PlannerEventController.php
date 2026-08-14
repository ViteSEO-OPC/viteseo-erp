<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;

use App\Modules\Crm\Requests\IndexPlannerEventRequest;
use App\Modules\Crm\Requests\StorePlannerEventRequest;
use App\Modules\Crm\Requests\UpdatePlannerEventRequest;
use App\Modules\Crm\Resources\PlannerEventResource;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Services\Planner\FollowUpScheduler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Planner events — the single scheduling surface (Calendar + Board). The list is ownership-scoped
 * via PlannerEvent::scopeVisibleTo (BD sees only events assigned to them; Admin/SA see all); single-record
 * routes authorize through PlannerEventPolicy. Every write logs a `planner-event.*` Activity via
 * PlannerEventObserver, so the controller stays thin.
 */
class PlannerEventController extends Controller
{
    /**
     * How long a completed event stays on a BD's Planner after it was completed. Rolling hours from
     * `completed_at`, deliberately NOT 7 calendar days — a rolling window needs no Asia/Manila
     * day-boundary maths and matches the rule as stated ("7 days after completed_at").
     */
    private const COMPLETED_VISIBLE_DAYS = 7;

    /**
     * List planner events visible to the caller. Optional filters power the two representations:
     * calendar views pass ?from/?to (starts_at range); the Board fetches all statuses. ?status
     * and ?event_type narrow further.
     *
     * A BD additionally sees only RECENTLY completed work — see {@see applyCompletedWindow()}. That
     * window lives here, in the view, and must never move onto PlannerEvent::scopeVisibleTo: every
     * KPI reads completed events through that scope with its OWN period (Completed Follow-ups is
     * month-to-date; Consultations Held has no date bound at all), so a 7-day scope would silently
     * shrink those figures as a month progressed. The Planner view is filtered; reporting is not.
     *
     * `?view=history` selects the History representation instead — see {@see applyHistory()}. It is
     * the SAME endpoint, scope, and policy: History is a different question asked of the same rows,
     * not a second Planner.
     *
     * `?assigned_to` narrows to one BD (the Admin/Super Admin "All BD | <BD>" filter). It is applied
     * AFTER visibleTo and can therefore only ever intersect what the caller may already see — a BD
     * passing another BD's id gets an empty list, never a leak. That is why no role check guards the
     * parameter: one ownership boundary, in one place.
     */
    public function index(IndexPlannerEventRequest $request): AnonymousResourceCollection
    {
        $history = $request->wantsHistory();

        $events = PlannerEvent::query()
            ->visibleTo($request->user())
            ->with(['lead', 'owner'])
            ->when(! $history, fn (Builder $q) => $this->applyCompletedWindow($q, $request->user()))
            ->when($history, fn (Builder $q) => $this->applyHistory($q))
            ->when($request->filled('assigned_to'), fn ($q) => $q->where('assigned_to', $request->integer('assigned_to')))
            ->when($request->filled('from'), fn ($q) => $q->where('starts_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('starts_at', '<=', $request->date('to')))
            ->when(! $history && $request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('event_type'), fn ($q) => $q->where('event_type', $request->string('event_type')))
            ->when($history, fn (Builder $q) => $q->orderByDesc('completed_at')->orderByDesc('id'))
            ->when(! $history, fn (Builder $q) => $q
                ->orderByRaw('starts_at IS NULL')   // dated events first, undated (Board backlog) last
                ->orderBy('starts_at')
                ->orderByDesc('id'))
            ->get();

        return PlannerEventResource::collection($events);
    }

    /**
     * The History representation: completed work, with NO time limit.
     *
     * History is the answer to "what has been finished?", which is why it is completed-only and why
     * {@see applyCompletedWindow()} is skipped rather than widened — that method is untouched and
     * still governs the live Planner exactly as before, including its KPI-safety property.
     *
     * `?status` is deliberately ignored in this mode (see index()): History means completed, and
     * honouring `?status=todo` here would return an empty list that reads like missing data rather
     * than a contradiction in terms.
     *
     * Ownership still comes from scopeVisibleTo, so a BD's History is their own completed work and
     * nobody else's. Completed events are never ownership-transferred (a lead reassignment moves
     * only OPEN work), so History correctly attributes each row to the BD who actually performed it.
     *
     * @param  Builder<PlannerEvent>  $query
     */
    private function applyHistory(Builder $query): void
    {
        $query->where('status', 'completed');
    }

    /**
     * Retire completed work from a BD's Planner once it is older than {@see COMPLETED_VISIBLE_DAYS}.
     *
     * The Planner answers "what do I owe, and what did I just finish?" — a completion from three
     * months ago is history, and history belongs to the lead timeline and to reporting, both of which
     * still hold every row. NOTHING IS DELETED and no completed record is modified.
     *
     * Three deliberate rules, each of which changes behaviour:
     *
     *  - **Admin / Super Admin are exempt.** They are the oversight roles, and quietly hiding records
     *    from oversight is a worse failure than a longer list. Mirrors how scopeVisibleTo branches.
     *
     *  - **`completed_at IS NULL` is hidden.** These are legacy rows completed before the column
     *    existed, so they are old by definition. Note this falls out of SQL NULL semantics anyway —
     *    it is stated explicitly so it reads as the decision it is, not an accident of the comparison.
     *
     *  - **A consultation with no recorded outcome NEVER expires.** This one is load-bearing, not a
     *    nicety: the Board card is the only BD-reachable place to record an outcome (`crm_bd` does not
     *    hold `crm.reports.view`), and a consultation only counts toward Consultations Held once its
     *    outcome says the lead attended. Letting the card vanish at day 7 would strand the row in
     *    "awaiting outcome" permanently and put a hole in the KPI that no BD could close.
     *
     * Reopening is handled for free: PlannerEventObserver clears `completed_at` when an event leaves
     * `completed`, so a reopened event matches the first clause below and returns immediately.
     *
     * @param  Builder<PlannerEvent>  $query
     */
    private function applyCompletedWindow(Builder $query, User $user): void
    {
        if ($user->hasAnyRole(['crm_admin', 'super_admin'])) {
            return;
        }

        $cutoff = now()->subDays(self::COMPLETED_VISIBLE_DAYS);

        // Grouped so the OR cannot leak past the surrounding filters (?status, ?from/?to, ownership).
        $query->where(function (Builder $visible) use ($cutoff): void {
            $visible->where('status', '!=', 'completed')            // all open work, untouched
                ->orWhere('completed_at', '>=', $cutoff)            // recently completed (NULL fails → hidden)
                ->orWhere(fn (Builder $awaiting) => $awaiting       // consultation still owed an outcome
                    ->where('event_type', 'consultation')
                    ->whereNull('outcome'));
        });
    }

    /**
     * Create an event. `created_by` = caller; a BD creator auto-owns the event (mirrors lead
     * self-assign), Admin/SA pass the owning BD (enforced by the request). `source` is a controlled
     * domain value: an integrated consumer (e.g. Nearby Clients) may post its own origin, otherwise
     * it defaults to 'manual'. Never accepted as free text — the request validates the enum.
     */
    public function store(StorePlannerEventRequest $request, FollowUpScheduler $followUps): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if ($user->hasRole('crm_bd')) {
            $data['assigned_to'] = $user->id;
        }
        $data['created_by'] = $user->id;

        // Single authoritative creator: a follow-up — from any surface, including this generic
        // endpoint — is minted only by FollowUpScheduler, so the event_type invariant and its
        // defaults can never diverge across callers. Every other type is created here directly.
        if (($data['event_type'] ?? null) === 'follow-up') {
            $lead = isset($data['lead_id']) ? Lead::find($data['lead_id']) : null;
            $event = $followUps->create($lead, $data);
        } else {
            $event = PlannerEvent::create([
                ...$data,
                'source' => $data['source'] ?? 'manual',
            ]);
        }

        return (new PlannerEventResource($event->load(['lead', 'owner'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(PlannerEvent $plannerEvent): JsonResponse
    {
        return (new PlannerEventResource($plannerEvent->load(['lead', 'owner'])))->response();
    }

    /** Partial update — also the Board click-to-move path (a lone `status` change). */
    public function update(UpdatePlannerEventRequest $request, PlannerEvent $plannerEvent): JsonResponse
    {
        $plannerEvent->update($request->validated());

        return (new PlannerEventResource($plannerEvent->load(['lead', 'owner'])))->response();
    }

    public function destroy(PlannerEvent $plannerEvent): JsonResponse
    {
        $plannerEvent->delete();

        return response()->json(null, 204);
    }
}
