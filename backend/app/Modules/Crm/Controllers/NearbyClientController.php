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
 * Returns leads landed by a discovery provider ({@see Lead::scopeDiscovered}). Unlike owned leads,
 * this is a shared prospecting surface (discovered businesses are unassigned), so every CRM user
 * (BDM and up) sees the same pool — matching the former demo behaviour. It supplies data only:
 * no score, KPI, ownership, or engagement value is computed here, and the response is
 * provider-agnostic (the supplying provider is never exposed).
 */
class NearbyClientController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $clients = Lead::query()
            ->discovered()
            ->orderBy('business_name')
            ->get();

        return NearbyClientResource::collection($clients);
    }
}
