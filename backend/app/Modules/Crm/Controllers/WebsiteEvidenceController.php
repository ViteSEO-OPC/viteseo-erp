<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadWebsiteCandidate;
use App\Modules\Crm\Requests\ConfirmWebsiteAbsenceRequest;
use App\Modules\Crm\Requests\StoreWebsiteCandidatesRequest;
use App\Modules\Crm\Requests\StoreWebsiteCandidateVerificationRequest;
use App\Modules\Crm\Services\Website\WebsiteEvidenceService;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The website-evidence chain: what was proposed, what was refused, and the human conclusion.
 *
 * FOUR ENDPOINTS, TWO AUDIENCES. The discovery service submits candidates and their crawl findings
 * (`import` permission, like the ingest and audit queue it sits beside). A Business Development
 * Manager reads the resulting evidence and — only once it is exhausted — confirms that no credible
 * website exists (guarded by the lead policy, so a BD acts on leads they can already see).
 *
 * NOTHING HERE DERIVES A STATUS. Candidate adjudication reuses the unmodified
 * {@see \App\Modules\Crm\Services\WebsiteIdentityVerifier}; `WebsiteStatusDeriver` is untouched and
 * still cannot return `no-website`. The single write of that value lives in
 * {@see WebsiteEvidenceService::confirmAbsent()} and happens only on a person's instruction — the
 * same authority they already held through the lead-edit endpoint, now exercised against a
 * documented trail rather than a guess.
 */
class WebsiteEvidenceController extends Controller
{
    public function __construct(private readonly WebsiteEvidenceService $evidence) {}

    /**
     * Leads that still need a website search: discovered prospects with no URL of their own.
     *
     * Never-searched first, then least-recently-searched, so a growing pool is worked front-to-back
     * instead of the same leads being re-examined.
     */
    public function queue(): JsonResponse
    {
        $limit = min((int) request()->integer('limit', 50) ?: 50, 200);

        $leads = Lead::query()
            ->whereNull('crm_leads.website')
            ->whereNotNull('crm_leads.source')
            ->leftJoin('crm_lead_website_searches as s', 's.lead_id', '=', 'crm_leads.id')
            ->whereNull('s.confirmed_absent_at')
            ->whereNull('s.exhausted_at')
            // A lead whose candidate was VERIFIED is answered, even though `website` stays null —
            // this chain records evidence and never writes that column. Without this it would be
            // re-searched on every run, and a solved lead would crowd out unsearched ones forever.
            ->whereNotExists(fn ($q) => $q
                ->selectRaw('1')
                ->from('crm_lead_website_candidates as c')
                ->whereColumn('c.lead_id', 'crm_leads.id')
                ->where('c.verified', true))
            ->orderByRaw('s.last_searched_at IS NOT NULL')
            ->orderBy('s.last_searched_at')
            ->limit($limit)
            ->get(['crm_leads.*']);

        return response()->json([
            'data' => $leads->map(fn (Lead $lead): array => [
                'id' => (string) $lead->id,
                'business' => $lead->business_name,
                // Everything a candidate resolver needs to search, and nothing more.
                'city' => $lead->city,
                'province' => $lead->province,
                'region' => $lead->region,
                'industry' => $lead->industry,
                'placeId' => $lead->place_id,
            ])->all(),
        ]);
    }

    /** Record the candidates one search pass produced (possibly none) and which tiers ran. */
    public function storeCandidates(StoreWebsiteCandidatesRequest $request, Lead $lead): JsonResponse
    {
        $search = $this->evidence->recordCandidates(
            $lead,
            $request->validated('candidates'),
            $request->validated('tiers_run'),
        );

        // The caller may declare it has nothing further to try; the service still refuses to mark
        // exhaustion while any candidate is unchecked or verified.
        if ($request->boolean('exhausted')) {
            $search = $this->evidence->markExhausted($lead);
        }

        return $this->evidenceResponse($lead);
    }

    /** Adjudicate one candidate against the crawler's findings. */
    public function verifyCandidate(
        StoreWebsiteCandidateVerificationRequest $request,
        Lead $lead,
        LeadWebsiteCandidate $candidate,
    ): JsonResponse {
        // The nested binding is not self-verifying — a candidate id from another lead would
        // otherwise be adjudicated against the wrong business.
        abort_unless((int) $candidate->lead_id === (int) $lead->id, Response::HTTP_NOT_FOUND);

        $this->evidence->recordVerification($candidate, $request->validated());

        return $this->evidenceResponse($lead);
    }

    /** The full evidence trail for one lead — what a BD reads before deciding. */
    public function show(Lead $lead): JsonResponse
    {
        return $this->evidenceResponse($lead);
    }

    /**
     * A human confirms no credible website exists. The ONLY path to `no-website`.
     *
     * 409 rather than 422 when the evidence is not ready: the request is well-formed and the user is
     * entitled to make it — the system simply is not in a state where the claim can be justified yet.
     */
    public function confirmAbsence(ConfirmWebsiteAbsenceRequest $request, Lead $lead): JsonResponse
    {
        try {
            $this->evidence->confirmAbsent($lead, $request->user(), $request->validated('note'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->evidenceResponse($lead->refresh());
    }

    private function evidenceResponse(Lead $lead): JsonResponse
    {
        $search = $this->evidence->searchFor($lead);
        $candidates = $this->evidence->candidatesFor($lead);
        // A candidate re-checked AFTER exhaustion can come back verified (a site that was down is up
        // again). Exhaustion alone would then still read as confirmable, and the UI would offer a
        // button the service is right to refuse. Ask the candidates, not just the timestamp.
        $hasVerified = $candidates->contains(fn (LeadWebsiteCandidate $c): bool => $c->verified === true);

        return response()->json([
            'data' => [
                'leadId' => (string) $lead->id,
                'websiteStatus' => $lead->website_status,
                'search' => [
                    'searched' => $search->exists,
                    'tiersRun' => $search->tiers_run ?? [],
                    'candidatesFound' => (int) $search->candidates_found,
                    'candidatesRejected' => (int) $search->candidates_rejected,
                    'lastSearchedAt' => $search->last_searched_at?->toIso8601String(),
                    // Evidence that the available tiers were tried — NOT a verdict on its own.
                    'exhausted' => $search->isExhausted(),
                    'exhaustedAt' => $search->exhausted_at?->toIso8601String(),
                    'confirmedAbsent' => $search->isConfirmedAbsent(),
                    'confirmedAt' => $search->confirmed_absent_at?->toIso8601String(),
                    'confirmedBy' => $search->confirmedBy?->name,
                    'note' => $search->confirmation_note,
                    // A confirmation that a later verified website contradicted. Surfaced rather
                    // than hidden: the panel should say the conclusion was superseded, not quietly
                    // drop it as though nobody had ever decided.
                    'confirmationWithdrawn' => $search->isConfirmationInvalidated(),
                    'withdrawnAt' => $search->confirmation_invalidated_at?->toIso8601String(),
                    'withdrawnReason' => $search->confirmation_invalidated_reason,
                    // Whether a BD may act. Surfaced so the UI need not re-derive the rule.
                    'canConfirmAbsence' => $search->isExhausted()
                        && ! $search->isConfirmedAbsent()
                        && ! $hasVerified,
                ],
                'candidates' => $candidates->map(fn (LeadWebsiteCandidate $c): array => [
                    'id' => (string) $c->id,
                    'url' => $c->url,
                    'normalizedUrl' => $c->normalized_url,
                    'source' => $c->source,
                    'sourceDetail' => $c->source_detail,
                    // null = proposed but not yet checked; false = checked and refused.
                    'verified' => $c->verified,
                    'confidence' => $c->confidence,
                    'signals' => $c->signals ?? [],
                    'rejectionReason' => $c->rejection_reason,
                    'checkedAt' => $c->checked_at?->toIso8601String(),
                ])->all(),
            ],
        ]);
    }
}
