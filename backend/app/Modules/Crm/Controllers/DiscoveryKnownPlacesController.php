<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Requests\DiscoveryKnownPlacesRequest;
use Illuminate\Http\JsonResponse;

/**
 * Which of the submitted place keys this org already holds as leads — volume mode's pre-filter.
 *
 * AN OPTIMISATION, NEVER THE DEDUP. The discovered-lead ingest still deduplicates on `place_id` on its
 * own, so this answer only tells the engine which businesses are not worth a reverse-geocode or a slot
 * under its task cap. The lookup is the ingest's own (`LeadImportService::existingPlaceIdLeads`): the
 * same column, the same tenant scope.
 *
 * Reveals nothing beyond what was asked: the answer is a subset of the keys the caller sent.
 * Machine-only (EnsureNerbyServiceCaller), like every other discovery callback.
 */
class DiscoveryKnownPlacesController extends Controller
{
    public function __invoke(DiscoveryKnownPlacesRequest $request): JsonResponse
    {
        $asked = array_values(array_unique(array_map('strval', (array) $request->validated('place_ids'))));

        $known = Lead::query()
            ->whereIn('place_id', $asked)
            ->distinct()
            ->pluck('place_id')
            ->map(fn ($placeId): string => (string) $placeId)
            ->values()
            ->all();

        return response()->json(['data' => ['known' => $known]]);
    }
}
