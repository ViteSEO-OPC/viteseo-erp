<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Rules\MustBeBusinessDeveloper;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'industry' => ['nullable', 'string', 'max:255'],
            // These five have DB column defaults — omit to use the backend default; validated if provided.
            'website_status' => ['sometimes', Rule::in(['has-website', 'no-website', 'needs-redesign'])],
            'priority' => ['sometimes', Rule::in(['high', 'medium', 'low'])],
            'email_outreach' => ['sometimes', Rule::in(['sent', 'opened', 'replied', 'none'])],
            'cold_calling' => ['sometimes', Rule::in(['called', 'scheduled', 'not-called'])],
            'status' => ['sometimes', Rule::in(['interested', 'awaiting-reply', 'declined', 'call-back-req', 'new'])],
            'last_activity' => ['nullable', 'date'],
            'score' => ['nullable', 'integer', 'min:0', 'max:100'],
            // Estimated Deal Value (prospect-stage). NOT revenue — never merged with deals.amount.
            'amount' => ['nullable', 'numeric', 'min:0'],
            // Administrative-territory location (Nearby Clients / Target Location +10). Plain strings.
            'region' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            // Website Domain — the existing `website` column, now settable via create (previously
            // written only by discovery ingest). Nullable/optional.
            'website' => ['nullable', 'string', 'max:255'],
            // Digital Presence (social) + Marketplace Presence (e-commerce). All nullable/optional
            // plain strings — an existing lead or an import without these never fails. `tiktok`
            // (social) is distinct from `tiktok_shop` (marketplace).
            'facebook' => ['nullable', 'string', 'max:255'],
            'instagram' => ['nullable', 'string', 'max:255'],
            'tiktok' => ['nullable', 'string', 'max:255'],
            'linkedin' => ['nullable', 'string', 'max:255'],
            'shopee' => ['nullable', 'string', 'max:255'],
            'lazada' => ['nullable', 'string', 'max:255'],
            'tiktok_shop' => ['nullable', 'string', 'max:255'],
            'other_marketplace' => ['nullable', 'string', 'max:255'],
            // Required for Admin/SA (they must pick the owning BD); optional for a BD creator
            // (auto-assigned to self in the controller). LeadImportService reuses these rules via
            // `new StoreLeadRequest` with NO bound user — the `(bool) $this->user()` guard keeps
            // the closure from calling hasRole() on null there (assignment is applied per-import).
            'assigned_to' => [
                Rule::requiredIf(fn (): bool => (bool) $this->user() && ! $this->user()->hasRole('crm_bd')),
                'nullable', 'integer', 'exists:users,id', new MustBeBusinessDeveloper,
            ],
        ];
    }
}
