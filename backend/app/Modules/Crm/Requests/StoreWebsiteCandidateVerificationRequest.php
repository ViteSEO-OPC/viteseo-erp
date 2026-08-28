<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Audit findings for ONE candidate URL, submitted by the crawler.
 *
 * Deliberately the same shape as {@see StoreWebsiteEnrichmentRequest}: the crawler reports what it
 * SAW and the CRM decides what it MEANS. Reusing the shape means candidate verification and the
 * ordinary audit cannot drift apart in what evidence they consider.
 */
class StoreWebsiteCandidateVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route-level `can('import', Lead::class)` authorizes.
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'website_reachable' => ['required', 'boolean'],
            'website_title' => ['nullable', 'string', 'max:255'],
            'website_description' => ['nullable', 'string'],
            'final_url' => ['nullable', 'string', 'max:2048'],
            'canonical_url' => ['nullable', 'string', 'max:2048'],
            'phones_found' => ['sometimes', 'array'],
            'phones_found.*' => ['string', 'max:50'],
            'emails_found' => ['sometimes', 'array'],
            'emails_found.*' => ['string', 'max:255'],
        ];
    }
}
