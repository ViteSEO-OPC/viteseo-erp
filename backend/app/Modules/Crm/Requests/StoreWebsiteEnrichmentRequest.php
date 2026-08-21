<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Website Intelligence audit submission — the Scrapy provider reports its findings for one lead's
 * website. Route-authorized by `can('import', Lead::class)`.
 *
 * Accepts audit findings only. It cannot carry engagement / CRM-decision fields (priority, status,
 * email_outreach, cold_calling, score, assigned_to) — the enrichment is a strict data provider.
 * `website_status` is NOT accepted from the provider either; the backend derives it from these
 * findings via the approved availability+quality rule ({@see WebsiteStatusDeriver}).
 */
class StoreWebsiteEnrichmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route-level `can('import', Lead::class)` gate authorizes.
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Availability
            'website_reachable' => ['required', 'boolean'],
            'https_enabled' => ['required', 'boolean'],

            // Structure — core pages
            'about_page' => ['required', 'boolean'],
            'contact_page' => ['required', 'boolean'],
            'services_page' => ['required', 'boolean'],
            'careers_page' => ['nullable', 'boolean'],
            'blog_page' => ['nullable', 'boolean'],

            // Business information
            'emails_found' => ['nullable', 'array'],
            'emails_found.*' => ['string', 'max:255'],
            'phones_found' => ['nullable', 'array'],
            'phones_found.*' => ['string', 'max:50'],

            // Digital presence
            'facebook_url' => ['nullable', 'string', 'max:2048'],
            'linkedin_url' => ['nullable', 'string', 'max:2048'],
            'instagram_url' => ['nullable', 'string', 'max:2048'],
            'youtube_url' => ['nullable', 'string', 'max:2048'],

            // URL provenance — where the request actually LANDED, and what the page calls itself.
            // Identity verification runs against these rather than the URL we aimed at, so a redirect
            // to a parked page or an unrelated host is judged on where it ended up.
            'final_url' => ['nullable', 'string', 'max:2048'],
            'canonical_url' => ['nullable', 'string', 'max:2048'],

            // Site metadata
            'website_title' => ['nullable', 'string', 'max:255'],
            'website_description' => ['nullable', 'string', 'max:2000'],

            // Extended / future signals (extra page types, more socials, forms, maps embed, SEO, …).
            'audit' => ['nullable', 'array'],

            // Provenance
            'provider' => ['required', 'string', 'max:255'],
            'provider_version' => ['nullable', 'string', 'max:255'],
        ];
    }
}
