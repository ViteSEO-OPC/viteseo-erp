<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\DiscoveryRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One discovery run, as the poller sees it.
 *
 * PROVIDER HONESTY IS THE POINT OF THIS SHAPE. `requested` and `effective` are always reported
 * separately, alongside the reason they differ, so the UI can never imply that Google produced
 * results that OpenStreetMap actually produced. A fallback is a fact to state, not a detail to hide:
 * the three ways it happens — credential missing, provider disabled, credential REJECTED mid-run —
 * are each distinguishable here.
 *
 * No credential, key fragment, model name or provider option is exposed. The smallest payload that
 * answers "what is happening and who found it" is the one that cannot leak anything, which is the
 * same reasoning `GET /discovery/config` already follows.
 *
 * @mixin DiscoveryRun
 */
class DiscoveryRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $total = (int) $this->targets_total;
        $done = (int) $this->targets_completed + (int) $this->targets_failed;

        // PROGRESS IS MEASURED IN THE UNIT THE RUN IS PLANNED IN. A scoped run knows all its searches
        // up front, so searches-done over searches-planned is a real fraction. A nationwide run does
        // not: the descent creates searches in response to what earlier searches returned, so
        // `targets_total` climbs as the run proceeds and a percentage built on it would slide
        // backwards. Its FIRST-PASS cells, in contrast, are all known the moment planning finishes —
        // so the bar tracks how much of the country has been asked about, and the subdivision that
        // happens underneath is reported as its own number rather than moving the goalposts.
        $nationwide = $this->isNationwide();
        $planned = (int) $this->cells_planned;
        $percent = $nationwide
            ? ($planned > 0 ? (int) floor(min($planned, (int) $this->cells_first_pass_done) / $planned * 100) : 0)
            : ($total > 0 ? (int) floor($done / $total * 100) : 0);

        return [
            'runId' => $this->uuid,
            'status' => $this->status,
            'phase' => $this->phase,
            'scopeClass' => $this->scope_class,
            'scope' => $this->scope,

            'provider' => [
                'requested' => $this->provider_requested,
                'effective' => $this->provider_effective,
                'reason' => $this->provider_reason,
                'mode' => $this->provider_mode,
                'isFallback' => $this->isFallback(),
                // Set only when a credential that WAS present got rejected partway through a run —
                // the one degradation that cannot be known before execution starts.
                'degradedAt' => $this->provider_degraded_at?->toIso8601String(),
                'degradedReason' => $this->degraded_reason,
            ],

            // AI SEARCH PLANNING, reported the way the provider is: what was ASKED for, separately
            // from what HAPPENED. `outcome` is applied | not_applied | insufficient_credit and is
            // `applied` only once the engine reported a search actually shaped by a returned plan.
            // Status words only — no model, no prompt, no plan contents, no internal feedback.
            'ai' => [
                'requested' => (bool) $this->ai_strategy_requested,
                'outcome' => $this->aiOutcome(),
            ],

            'progress' => [
                'targetsTotal' => $total,
                'targetsCompleted' => (int) $this->targets_completed,
                'targetsFailed' => (int) $this->targets_failed,
                'percent' => $percent,

                // ── Nationwide only; all zero for a scoped run, which tiles nothing.
                //
                // THESE ARE CELLS, NOT CITIES. Coverage is the national polygon tiled into search
                // boxes; the city labels attached to those boxes are for reading a run, and appear
                // in no number here.
                'cellsPlanned' => $planned,
                'cellsFirstPassDone' => (int) $this->cells_first_pass_done,
                // Every cell searched, including the ones subdivision created — so this exceeds
                // `cellsPlanned` exactly as much as the country turned out to need splitting.
                'cellsSearched' => (int) $this->cells_searched,
                'cellsSubdivided' => (int) $this->cells_subdivided,
                // THE TWO WAYS A CELL FINISHES, never collapsed. `exhausted` = the provider returned
                // fewer results than it was allowed, so there is nothing more there. `capped` = it
                // returned a full page and the descent limit stopped the split, so there IS more and
                // this run did not reach it. One is a fact about the ground, one about the run.
                'cellsExhausted' => (int) $this->cells_exhausted,
                'cellsCapped' => (int) $this->cells_capped,

                // The safety budget, in provider calls. Exposed so a run that stopped early can say
                // it stopped because it ran out of budget, rather than leaving its shortfall to be
                // read as a fact about the country.
                'budgetTotal' => (int) $this->budget_total,
                'budgetUsed' => (int) $this->budget_used,
            ],

            // `inserted` is what became CRM prospects; `duplicates` is what the existing ingest
            // recognised as already known. Both are reported because a run that finds 200 businesses
            // and inserts 4 has done useful work, and hiding that reads like a failure.
            'counters' => [
                'discovered' => (int) $this->discovered,
                'inserted' => (int) $this->inserted,
                'duplicates' => (int) $this->duplicates,
                'rejected' => (int) $this->rejected,
            ],

            'errorMessage' => $this->error_message,
            'isTerminal' => $this->isTerminal(),

            /**
             * WHY THE RUN IS WHERE IT IS: queued | running | exhausted | budget_limited | cancelled |
             * failed | completed.
             *
             * Stated rather than left to be inferred from counters, because two of these produce
             * identical numbers and opposite meanings. `exhausted` means the provider had nothing
             * more to give at this resolution for these niches — a narrower claim than "there is
             * nothing there". `budget_limited` means the run stopped with ground demonstrably left,
             * and licenses no claim about the country at all.
             */
            'stopReason' => $this->stopReason(),

            'startedAt' => $this->started_at?->toIso8601String(),
            'finishedAt' => $this->finished_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),

            // Per-task detail, only when the caller asked for it (the poller does; the list does not).
            'targets' => $this->whenLoaded('targets', fn () => $this->targets->map(fn ($t) => [
                'area' => $t->area,
                'category' => $t->category,
                'status' => $t->status,
                // Which provider actually answered THIS task — not which one the run hoped for.
                'providerUsed' => $t->provider_used,
                'executionStatus' => $t->execution_status,
                'discovered' => (int) $t->discovered,
                'inserted' => (int) $t->inserted,
                'duplicates' => (int) $t->duplicates,
                'errorReason' => $t->error_reason,
                // Where this search actually looked. NULL for a scoped task, which named a place and
                // let the engine's geocoder resolve it — a different thing from an unknown box.
                'bbox' => $t->bbox(),
                'islandGroup' => $t->island_group,
                // 0 is a first-pass cell; deeper means an ancestor came back truncated and was split.
                'depth' => (int) $t->depth,
            ])->all()),

            /**
             * PER-AREA SUMMARY — labelling, not accounting.
             *
             * Grouped from the searches after the fact so a person can read "Cebu" instead of
             * "cell 412". There is no per-area target, no per-area budget and no per-area row: delete
             * this block and not one search would move. That is the test of a label.
             */
            'areas' => $this->when($nationwide, fn () => $this->areaSummary()->map(fn ($a) => [
                'area' => $a->area,
                'islandGroup' => $a->island_group,
                'cells' => (int) $a->cells,
                'discovered' => (int) $a->discovered,
                'inserted' => (int) $a->inserted,
                'duplicates' => (int) $a->duplicates,
                'maxDepth' => (int) $a->max_depth,
            ])->all()),
        ];
    }
}
