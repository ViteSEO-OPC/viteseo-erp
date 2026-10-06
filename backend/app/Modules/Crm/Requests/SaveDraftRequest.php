<?php

namespace App\Modules\Crm\Requests;

use App\Support\CurrentOrg;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for saving/updating an outreach draft. Unlike SendEmailRequest (which requires a
 * subject and body because it dispatches immediately), a draft is work-in-progress and may be
 * partial — every field is optional. Authorization is handled on the route (create) or by
 * EmailPolicy (update). Only business data is accepted: recipient, subject, body, and the template
 * the draft was composed from.
 */
class SaveDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            // Optional recipient override; defaults to the lead's email when the draft is sent.
            'to' => ['nullable', 'email', 'max:255'],
            // The template this draft was composed from, while its text is still the template's own — so
            // sending the draft later renders that template's design exactly as a Send-Now would. An id,
            // never markup. Scoped to THIS organization's live templates: `exists` reads the table
            // directly, past the BelongsToOrg and SoftDeletes scopes, so without the two constraints
            // another organization's id (or a deleted one) would validate and be stored on the row.
            'email_template_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('crm_email_templates', 'id')
                    ->where(fn ($query) => $query->where('org_id', CurrentOrg::id())->whereNull('deleted_at')),
            ],
        ];
    }
}
