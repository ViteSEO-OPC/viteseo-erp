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
            'website_status' => ['sometimes', 'required', Rule::in(['has-website', 'no-website', 'needs-redesign', 'website-unknown', 'website-unreachable'])],
            'priority' => ['sometimes', 'required', Rule::in(['high', 'medium', 'low'])],
            // Validated against the enums that own these two columns — same source as the read filter.
            'email_outreach' => ['sometimes', 'required', Rule::enum(LeadEmailOutreach::class)],
            'cold_calling' => ['sometimes', 'required', Rule::enum(LeadColdCalling::class)],
            'status' => ['sometimes', 'required', Rule::in(['interested', 'awaiting-reply', 'declined', 'call-back-req', 'new'])],
            'last_activity' => ['nullable', 'date'],
            // NOTE: `score` is deliberately NOT accepted. LeadScorer is the only writer of `leads.score`
            // (R-4). The controller applies `$request->validated()`, so a field with no rule never
            // reaches the model: a client-sent score is dropped, not rejected, like `assigned_to` below.
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
            // NOTE: `assigned_to` is deliberately NOT accepted here. Ownership changes go through
            // PATCH /leads/{lead}/assign — the single source of truth — which is the only path that
            // enforces `crm.leads.assign` (Admin/SA), validates the new owner is a BD
            // (MustBeBusinessDeveloper), records the dedicated `lead.assigned` activity, and
            // broadcasts LeadAssigned. Accepting it here let a BD reassign their own lead and let
            // any caller hand a lead to a non-BD, both silently and without a realtime event.
            // Same convention as IngestDiscoveredLeadsRequest: omit the field so it can never be set.
        ];
    }
}
