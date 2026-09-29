<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Crm\Resources\NearbyClientResource;
use App\Modules\Crm\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Nearby Clients — the discovered-business prospect pool for the BD-facing map/list view.
 *
 * Returns every discovered business ({@see Lead::scopeDiscovered}) — both the unclaimed Prospect
 * Pool and the ones already accepted into the CRM. A claimed business stays visible, carrying
 * read-only `qualified` / `qualifiedBy` metadata so the UI can mark it and disable its Accept
 * action for every other BD. Unlike owned leads, this is a shared prospecting surface, so every
 * CRM user (BDM and up) sees the same list. UNCLAIMED prospects take NO part in Business
 * Computation; qualifying one makes it an Active CRM Lead that counts everywhere — it stays listed
 * here afterwards for provenance, but this surface supplies data only: no score, KPI, ownership or
 * engagement value is computed here, and the response is provider-agnostic (the supplying provider
 * is never exposed).
 */
class NearbyClientController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // EVERY discovered business, claimed or not — `discovered()` is `source IS NOT NULL`, so
        // hand-created and imported leads still never appear here. A prospect that a BD has accepted
        // stays on this surface rather than vanishing, marked and locked, so the BD who lost the race
        // sees WHO took it instead of watching the row disappear. This is a read-only widening of the
        // view: `scopeProspect` is untouched (Activity visibility still depends on it), and
        // qualification ownership remains decided solely by the locked transaction in
        // LeadQualificationController.
        $clients = Lead::query()
            ->discovered()
            ->with([
                'websiteEnrichment',   // Website Intelligence signals (1:1; null until audited).
                'websiteSearch',       // Website search trail (1:1; null until searched) — `checked` below.
                'qualifiedBy:id,name', // Column-limited: the "Added by" line needs a name, nothing else.
                'discoveryLanes:id,lead_id,lane', // Additional provenance for `foundByAll` (one query).
            ])
            ->orderBy('business_name')
            ->get();

        return NearbyClientResource::collection($clients);
    }
}
