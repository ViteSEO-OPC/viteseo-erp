<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Explicit confirmation for "Archive all leads".
 *
 * The dialog in front of this action is a UI affordance, and a UI affordance is not a safeguard: the
 * endpoint is reachable by anything holding the caller's token. Requiring an `accepted` flag in the
 * BODY means an intent-free call — a replayed URL, a curious `POST`, a mis-wired client — is refused
 * by validation rather than archiving a tenant's entire pipeline. It costs one field and makes the
 * confirmation a property of the API instead of a property of the browser.
 */
class DestroyAllLeadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route authorizes: role:super_admin|crm_admin + permission:crm.leads.delete, and the
        // controller re-authorizes LeadPolicy@delete per record.
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'confirm.required' => 'Archiving every lead must be explicitly confirmed.',
            'confirm.accepted' => 'Archiving every lead must be explicitly confirmed.',
        ];
    }
}
