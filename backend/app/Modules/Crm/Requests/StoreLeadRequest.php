<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Enums\LeadColdCalling;
use App\Modules\Crm\Enums\LeadEmailOutreach;
use App\Modules\Crm\Rules\MustBeBusinessDeveloper;
use App\Support\OrgUserRule;
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
            'website_status' => ['sometimes', Rule::in(['has-website', 'no-website', 'needs-redesign', 'website-unknown', 'website-unreachable'])],
            'priority' => ['sometimes', Rule::in(['high', 'medium', 'low'])],
            // Validated against the enums that own these two columns — same source as the read filter.
            'email_outreach' => ['sometimes', Rule::enum(LeadEmailOutreach::class)],
            'cold_calling' => ['sometimes', Rule::enum(LeadColdCalling::class)],
            'status' => ['sometimes', Rule::in(['interested', 'awaiting-reply', 'declined', 'call-back-req', 'new'])],
            'last_activity' => ['nullable', 'date'],
            'score' => ['nullable', 'integer', 'min:0', 'max:100'],
            // Estimated Deal Value (prospect-stage). NOT revenue — never merged with deals.amount.
            'amount' => ['nullable', 'numeric', 'min:0'],
            // Lead Acquisition Channel — the business "Source" (Referral, Facebook, Website, Cold
            // Outreach, LinkedIn, Walk-in, Event, Partner, Email Campaign, Other). SEPARATE from the
            // `source` provenance column (untouched). Free string (not a DB enum) so an enterprise
            // import never loses a channel that isn't in the canonical dropdown; the wizard supplies
            // canonical values. Participates in NO business computation.
            'acquisition_channel' => ['nullable', 'string', 'max:100'],
            // Street address. The column and the Fillable entry already existed — it was written only
            // by discovery ingest, so the wizard's "Business Address" input had nowhere to go and was
            // silently discarded. Nullable/optional: no existing caller is affected by adding it.
            'address' => ['nullable', 'string', 'max:255'],
            // Administrative-territory location (Nearby Clients / Target Location +10). Plain strings.
            'region' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            // Website Domain — the existing `website` column, now settable via create (previously
            // written only by discovery ingest). Nullable/optional.
            'website' => ['nullable', 'string', 'max:255'],
            // Optional FIRST internal note for the lead. Singular on purpose: it creates exactly one
            // `crm_notes` row through the same service the Lead Details composer uses — it is NOT a
            // column on `crm_leads` and NOT a collection. Constraint mirrors StoreNoteRequest's `body`
            // so the same text is accepted whether it is typed in the wizard or on Lead Details.
            'note' => ['nullable', 'string', 'max:5000'],
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
            // OPTIONAL for every creator — a lead may be created unassigned.
            //
            // This was `Rule::requiredIf(... ! hasRole('crm_bd'))`, which made an Admin or Super
            // Admin unable to create a lead at all without naming an owning BD. That is a real
            // workflow: a lead arrives before anyone has been chosen to work it, and the alternative
            // — parking it on an arbitrary BD — is worse than leaving it unowned, because it puts
            // the lead in someone's pipeline and their performance figures.
            //
            // Nothing else about assignment changed. A BD creator is still auto-assigned to self in
            // {@see \App\Modules\Crm\Services\LeadCreator}, so a BD cannot create a lead they do
            // not own. When a value IS supplied it is validated exactly as before: OrgUserRule fences
            // it to the current tenant, and MustBeBusinessDeveloper still refuses an admin, a
            // suspended account, or anyone who does not carry leads. `assigned_to` has always been a
            // nullable column with a nullOnDelete foreign key, so no migration is involved and
            // unassigned is a state the schema and the read paths already understood.
            //
            // LeadImportService reuses these rules via `new StoreLeadRequest` with no bound user;
            // dropping the closure removes that binding's only significance here.
            'assigned_to' => [
                'nullable', 'integer', OrgUserRule::exists(), new MustBeBusinessDeveloper,
            ],
        ];
    }
}
