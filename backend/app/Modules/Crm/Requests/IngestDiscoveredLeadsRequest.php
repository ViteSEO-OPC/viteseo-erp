<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Programmatic enrichment ingest — a discovery provider (Python Scraper) submits a batch of
 * normalized, discovered businesses as JSON. Route-authorized by `can('import', Lead::class)`.
 *
 * The accepted field set is **fit-data only** (business identity, contactability, website presence,
 * administrative location, coordinates, provenance). It deliberately does NOT accept the engagement /
 * CRM-decision fields — `priority`, `status`, `email_outreach`, `cold_calling`, `score`, `assigned_to`
 * — so a data provider can never set them: those remain CRM decisions applied elsewhere. This mirrors
 * the frozen Lead Enrichment Principle: discover / enrich only; never change CRM business decisions.
 */
class IngestDiscoveredLeadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route-level `can('import', Lead::class)` gate authorizes.
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'dry_run' => ['sometimes', 'boolean'],

            'businesses' => ['required', 'array', 'min:1', 'max:500'],

            'businesses.*.business_name' => ['required', 'string', 'max:255'],
            // Discovered businesses rarely expose a named contact person; when absent the ingest
            // service defaults contact_name to the business name (a documented normalization, not
            // fabricated data). Never required from the provider.
            'businesses.*.contact_name' => ['nullable', 'string', 'max:255'],
            'businesses.*.email' => ['nullable', 'email', 'max:255'],
            'businesses.*.phone' => ['nullable', 'string', 'max:50'],
            'businesses.*.industry' => ['nullable', 'string', 'max:255'],
            // Real website detection is a legitimate enrichment output (unlike the human CSV import,
            // which repurposes its "Website" column as Priority and never sets website_status).
            'businesses.*.website_status' => ['nullable', Rule::in(['has-website', 'no-website', 'needs-redesign', 'website-unknown'])],

            'businesses.*.region' => ['nullable', 'string', 'max:255'],
            'businesses.*.province' => ['nullable', 'string', 'max:255'],
            'businesses.*.city' => ['nullable', 'string', 'max:255'],

            'businesses.*.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'businesses.*.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'businesses.*.place_id' => ['nullable', 'string', 'max:255'],
            'businesses.*.source' => ['required', 'string', 'max:255'],
            // PROVENANCE, NOT A CRM DECISION — which is why it belongs in this allowlist: it records
            // how the search that found the business was planned, and nothing downstream branches on
            // it beyond the label the board shows.
            //
            // `nullable` and never `required`: a lane that does not record the fact simply omits it
            // and the column stays NULL. `boolean` accepts the JSON `false` the Google lane sends to
            // say "recorded, and AI did not shape this search", which is a different claim from
            // saying nothing at all.
            'businesses.*.discovery_ai_assisted' => ['nullable', 'boolean'],
            // OTHER LANES THAT FOUND THIS SAME BUSINESS IN THE SAME SEARCH (change set D). Recorded as
            // additional provenance in `crm_lead_discovery_lanes`, never on the lead. Only the AI lane
            // may be claimed: Google Places provenance is never written through this field.
            'businesses.*.discovery_also_found_by' => ['nullable', 'array', 'max:5'],
            'businesses.*.discovery_also_found_by.*' => ['string', Rule::in(['openai'])],
            // The website URL (fit-data) — the input the Website Intelligence service later audits.
            //
            // LENGTH IS NORMALIZED, NOT REJECTED — the same reasoning as `website_status` above. A
            // `max:` here fails the WHOLE request, so a single pathological provider URL would 422 a
            // batch of 500 good businesses; the scraper raises on that response and the entire
            // discovery target dies, which is precisely the data loss this rule would appear to be
            // preventing. LeadImportService drops a value beyond the storage ceiling
            // (`WEBSITE_MAX_LENGTH`) and keeps the business, so an oversized URL costs its own field
            // and nothing else. Body size is bounded by the 500-business cap and PHP's own limits.
            'businesses.*.website' => ['nullable', 'string'],
        ];
    }
}
