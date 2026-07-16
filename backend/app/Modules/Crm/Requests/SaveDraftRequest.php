<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for saving/updating an outreach draft. Unlike SendEmailRequest (which requires a
 * subject and body because it dispatches immediately), a draft is work-in-progress and may be
 * partial — every field is optional. Authorization is handled on the route (create) or by
 * EmailPolicy (update). Only business data is accepted: recipient, subject, body.
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
        ];
    }
}
