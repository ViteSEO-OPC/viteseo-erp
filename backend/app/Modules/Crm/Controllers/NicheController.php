<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Niche;
use App\Modules\Crm\Requests\StoreNicheRequest;
use App\Modules\Crm\Requests\UpdateNicheRequest;
use App\Modules\Crm\Resources\NicheResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Business Niche registry management — the vocabulary behind the shared Business Niche selector
 * and the Add Business Niche modal.
 *
 * This is business CONFIGURATION, not lead data. Creating, renaming or deleting a niche never
 * changes a lead's status, score, priority, ownership or any KPI; the only lead field it can touch
 * is the `industry` string itself, and only on rename (see {@see update()}).
 *
 * Available to any authenticated CRM user (PO decision for this milestone) — no dedicated
 * `crm.niches.*` permission. The two protections that DO exist are data-integrity rules, enforced
 * here so they cannot be bypassed by calling the API directly:
 *   1. a built-in niche can never be renamed or deleted;
 *   2. a niche still used by any lead can never be deleted.
 */
class NicheController extends Controller
{
    /** Built-ins first, then custom, each alphabetical — the exact order the selector renders. */
    public function index(): AnonymousResourceCollection
    {
        $niches = Niche::query()->inSelectorOrder()->get();

        // One grouped query for every niche's lead count, instead of a COUNT per row. Keyed by the
        // lower-cased industry value so it matches the same case-insensitively as Niche::leadCount().
        $counts = Lead::query()
            ->whereNotNull('industry')
            ->where('industry', '!=', '')
            ->groupBy(DB::raw('LOWER(industry)'))
            ->selectRaw('LOWER(industry) as industry_key, COUNT(*) as lead_count')
            ->pluck('lead_count', 'industry_key');

        $niches->each(function (Niche $niche) use ($counts): void {
            $niche->lead_count = (int) $counts->get(mb_strtolower((string) $niche->name), 0);
        });

        return NicheResource::collection($niches);
    }

    /**
     * Register a new CUSTOM niche. Built-ins are never created through the API — they come from the
     * approved canonical list via NicheSeeder.
     */
    public function store(StoreNicheRequest $request): JsonResponse
    {
        $niche = Niche::create([
            'name' => $request->string('name')->value(),
            'is_custom' => true,
        ]);

        return (new NicheResource($niche))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Rename a custom niche, carrying its leads with it.
     *
     * A niche's identity in the registry is its row, but a LEAD's niche is the `industry` string, so
     * a rename that only touched this table would silently orphan every lead still holding the old
     * text. Both writes therefore happen in one transaction: rename the row, then repoint the leads.
     */
    public function update(UpdateNicheRequest $request, Niche $niche): JsonResponse
    {
        $this->assertCustom($niche, 'renamed');

        $previousName = (string) $niche->name;
        $newName = $request->string('name')->value();

        DB::transaction(function () use ($niche, $previousName, $newName): void {
            $niche->update(['name' => $newName]);
            Niche::repointLeads($previousName, $newName);
        });

        return (new NicheResource($niche->refresh()))->response();
    }

    /**
     * Delete a custom niche that no lead is using.
     *
     * Hard delete: the registry is a vocabulary list, not lead data, and the in-use guard means a
     * deletion can never take a lead's niche with it. Nothing references the row by id.
     */
    public function destroy(Niche $niche): JsonResponse
    {
        $this->assertCustom($niche, 'deleted');

        if ($niche->isInUse()) {
            abort(
                Response::HTTP_CONFLICT,
                sprintf('"%s" is still assigned to leads and can\'t be deleted.', $niche->name),
            );
        }

        $niche->delete();

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }

    /** Guard the built-in vocabulary: approved niches are immutable through the API. */
    private function assertCustom(Niche $niche, string $action): void
    {
        if (! $niche->is_custom) {
            abort(
                Response::HTTP_FORBIDDEN,
                sprintf('"%s" is a built-in business niche and can\'t be %s.', $niche->name, $action),
            );
        }
    }
}
