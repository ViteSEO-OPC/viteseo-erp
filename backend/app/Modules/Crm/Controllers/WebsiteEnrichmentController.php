<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Crm\Requests\StoreWebsiteEnrichmentRequest;
use App\Modules\Crm\Resources\WebsiteEnrichmentResource;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Website\LlmIdentityAdjudicator;
use App\Modules\Crm\Services\WebsiteIdentityVerifier;
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
    public function update(
        StoreWebsiteEnrichmentRequest $request,
        Lead $lead,
        WebsiteStatusDeriver $deriver,
        WebsiteIdentityVerifier $verifier,
        LlmIdentityAdjudicator $adjudicator,
    ): JsonResponse {
        $data = $request->validated();
        $now = now();

        // Does this page actually belong to THIS business? A reachable page proves only that some
        // server answered — the URL may be a mis-tagged node, a parked domain, or a redirect to an
        // unrelated host. The verdict is persisted alongside the findings so the decision is auditable
        // rather than an invisible enum flip.
        $verdict = $verifier->verify($lead, $data);

        // Optional ADVISORY second opinion, for the ambiguous band only — one strong signal that
        // nothing corroborated, which the lexical verifier must leave unconfirmed but a reader often
        // would not. It is deliberately NOT allowed to change the verdict: `$verdict` is never
        // reassigned, so `identity_verified`, `identity_confidence`, and the derived `website_status`
        // below are exactly what they would be with this feature absent. `website_status` is a frozen
        // Lead Score input, so promoting an identity here would move scores — the opinion is recorded
        // as evidence for a human instead, and nothing downstream consumes it.
        $aiEvidence = $adjudicator->evidenceFor($lead, $data, $verdict);

        $enrichment = $lead->websiteEnrichment()->updateOrCreate(
            ['lead_id' => $lead->id],
            [
                ...$data,
                'identity_verified' => $verdict->verified,
                'identity_confidence' => $verdict->confidence,
                'identity_signals' => $aiEvidence === null
                    ? $verdict->signals
                    : [...$verdict->signals, 'ai_adjudication' => $aiEvidence],
                'last_checked_at' => $now,
                'enriched_at' => $now,
            ],
        );

        // Derive the coarse website_status (the ONLY lead field this provider writes).
        $status = $deriver->derive(
            hasUrl: filled($lead->website),
            reachable: (bool) $data['website_reachable'],
            identityVerified: $verdict->verified,
            https: (bool) $data['https_enabled'],
            aboutPage: (bool) $data['about_page'],
            contactPage: (bool) $data['contact_page'],
            servicesPage: (bool) $data['services_page'],
        );
        $lead->update(['website_status' => $status]);

        return response()->json([
            'data' => new WebsiteEnrichmentResource($enrichment->refresh()),
            'websiteStatus' => $status,
        ]);
    }
}
