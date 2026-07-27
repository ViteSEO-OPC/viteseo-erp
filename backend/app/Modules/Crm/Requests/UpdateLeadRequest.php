<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Enums\LeadColdCalling;
use App\Modules\Crm\Enums\LeadEmailOutreach;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'business_name' => ['sometimes', 'required', 'string', 'max:255'],
            'contact_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'industry' => ['nullable', 'string', 'max:255'],
            'website_status' => ['sometimes', 'required', Rule::in(['has-website', 'no-website', 'needs-redesign'])],
            'priority' => ['sometimes', 'required', Rule::in(['high', 'medium', 'low'])],
            // Validated against the enums that own these two columns — same source as the read filter.
            'email_outreach' => ['sometimes', 'required', Rule::enum(LeadEmailOutreach::class)],
            'cold_calling' => ['sometimes', 'required', Rule::enum(LeadColdCalling::class)],
            'status' => ['sometimes', 'required', Rule::in(['interested', 'awaiting-reply', 'declined', 'call-back-req', 'new'])],
            'last_activity' => ['nullable', 'date'],
            'score' => ['nullable', 'integer', 'min:0', 'max:100'],
            // Estimated Deal Value (prospect-stage). NOT revenue — never merged with deals.amount.
            'amount' => ['nullable', 'numeric', 'min:0'],
            // Lead Acquisition Channel — business "Source"; SEPARATE from the `source` provenance
            // column. Free string; participates in NO business computation.
            'acquisition_channel' => ['nullable', 'string', 'max:100'],
            // Administrative-territory location (Nearby Clients / Target Location +10). Plain strings.
            'region' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            // Website Domain — the existing `website` column, now editable via update.
            'website' => ['nullable', 'string', 'max:255'],
            // Digital Presence (social) + Marketplace Presence (e-commerce). All nullable/optional;
            // `tiktok` (social) is distinct from `tiktok_shop` (marketplace).
            'facebook' => ['nullable', 'string', 'max:255'],
            'instagram' => ['nullable', 'string', 'max:255'],
            'tiktok' => ['nullable', 'string', 'max:255'],
            'linkedin' => ['nullable', 'string', 'max:255'],
            'shopee' => ['nullable', 'string', 'max:255'],
            'lazada' => ['nullable', 'string', 'max:255'],
            'tiktok_shop' => ['nullable', 'string', 'max:255'],
            'other_marketplace' => ['nullable', 'string', 'max:255'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
