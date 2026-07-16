<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Crm\Requests\StoreWebsiteEnrichmentRequest;
use App\Modules\Crm\Resources\WebsiteEnrichmentResource;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\WebsiteStatusDeriver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Website Intelligence — the audit provider's interface into the CRM (strict data provider).
 *
 * `queue` hands the Scrapy service the businesses that have a website URL to audit (never-checked
 * first). `update` records one lead's audit findings on `lead_website_enrichments` and derives the
 * coarse `website_status` (fit-data) from them. It touches NO other lead field — no Lead Status,
 * score, priority, ownership, or workflow field. Interpretation/scoring stays in the future KPI Engine.
 */
class WebsiteEnrichmentController extends Controller
{
    /** Businesses with a website URL to audit — never-audited first, then oldest-checked. */
    public function queue(Request $request): JsonResponse
    {
        $limit = min((int) $request->integer('limit', 50) ?: 50, 200);

        // Join only for ordering (never-checked first, then oldest-checked); select Lead columns only
        // and eager-load the audit record for output.
        $leads = Lead::query()
            ->whereNotNull('crm_leads.website')
            ->leftJoin('crm_lead_website_enrichments as e', 'e.lead_id', '=', 'crm_leads.id')
            ->with('websiteEnrichment')
            ->orderByRaw('e.last_checked_at IS NOT NULL') // never-checked (NULL) first
            ->orderBy('e.last_checked_at')
            ->limit($limit)
            ->get(['crm_leads.*']);

        return response()->json([
            'data' => $leads->map(fn (Lead $lead): array => [
                'id' => (string) $lead->id,
                'business' => $lead->business_name,
                'website' => $lead->website,
                'websiteStatus' => $lead->website_status,
                'lastCheckedAt' => $lead->websiteEnrichment?->last_checked_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /** Record one lead's website audit findings and derive its website_status (fit-data only). */
    public function update(StoreWebsiteEnrichmentRequest $request, Lead $lead, WebsiteStatusDeriver $deriver): JsonResponse
    {
        $data = $request->validated();
        $now = now();

        $enrichment = $lead->websiteEnrichment()->updateOrCreate(
            ['lead_id' => $lead->id],
            [...$data, 'last_checked_at' => $now, 'enriched_at' => $now],
        );

        // Derive the coarse website_status (the ONLY lead field this provider writes).
        $status = $deriver->derive(
            (bool) $data['website_reachable'],
            (bool) $data['https_enabled'],
            (bool) $data['about_page'],
            (bool) $data['contact_page'],
            (bool) $data['services_page'],
        );
        $lead->update(['website_status' => $status]);

        return response()->json([
            'data' => new WebsiteEnrichmentResource($enrichment->refresh()),
            'websiteStatus' => $status,
        ]);
    }
}
