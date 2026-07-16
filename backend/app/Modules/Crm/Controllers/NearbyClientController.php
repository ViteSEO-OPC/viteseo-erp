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
 * Returns the Prospect Pool — discovered businesses NOT yet qualified into the CRM
 * ({@see Lead::scopeProspect}). Unlike owned leads, this is a shared prospecting surface (prospects
 * are unassigned), so every CRM user (BDM and up) sees the same pool. Prospects take NO part in
 * Business Computation; once qualified they leave this pool and become Active CRM Leads. It supplies
 * data only: no score, KPI, ownership, or engagement value is computed here, and the response is
 * provider-agnostic (the supplying provider is never exposed).
 */
class NearbyClientController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $clients = Lead::query()
            ->prospect() // The Prospect Pool: discovered businesses NOT yet qualified into the CRM.
            ->with('websiteEnrichment') // Website Intelligence signals (1:1; null until audited).
            ->orderBy('business_name')
            ->get();

        return NearbyClientResource::collection($clients);
    }
}
