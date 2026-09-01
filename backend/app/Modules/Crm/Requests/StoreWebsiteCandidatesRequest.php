<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Models\LeadWebsiteCandidate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The discovery service submitting candidate websites it found for a lead.
 *
 * Candidates only — this endpoint cannot set `website_status`, cannot mark anything verified, and
 * cannot claim absence. It records what was proposed and by which tier; adjudication happens
 * separately against the unmodified identity verifier, and the conclusion belongs to a human.
 *
 * `tiers_run` is required and separate from the candidate list because ZERO CANDIDATES IS A
 * MEANINGFUL RESULT: "we ran Wikidata and found nothing" is evidence, while an empty submission with
 * no tier named is silence. The two must not be indistinguishable.
 */
class StoreWebsiteCandidatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route-level `can('import', Lead::class)` authorizes.
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'candidates' => ['present', 'array', 'max:25'],
            'candidates.*.url' => ['required', 'string', 'max:2048'],
            'candidates.*.source' => ['required', Rule::in(LeadWebsiteCandidate::sources())],
            'candidates.*.source_detail' => ['nullable', 'string', 'max:255'],
            'tiers_run' => ['required', 'array', 'min:1'],
            'tiers_run.*' => ['string', 'max:40'],
            // The caller declaring it has nothing further to try. Exhaustion is still refused
            // server-side if any candidate is unchecked or verified, or if `tiers_run` does not yet
            // cover every WebsiteEvidenceService::REQUIRED_TIERS — a caller cannot exhaust a search
            // by declaring it finished before it ran.
            'exhausted' => ['sometimes', 'boolean'],
        ];
    }
}
