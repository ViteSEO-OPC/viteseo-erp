<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Rules\MustBeBusinessDeveloper;
use App\Support\OrgUserRule;
use Illuminate\Foundation\Http\FormRequest;

class AssignLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Assign / reassign a lead to a BD, or clear the assignment with an explicit null.
            // Route is guarded by leads.assign (Admin/SA); the policy check is unchanged, so
            // clearing an owner requires exactly the authority reassigning one already did.
            //
            // `present` + `nullable`, NOT `sometimes`: the key must be in the body, but may be null.
            // That distinction is the safeguard — omitting the field is a malformed request and is
            // rejected, so a client can never clear a lead's owner by forgetting to send it.
            //
            // When a value IS given it is validated exactly as before: OrgUserRule fences it to the
            // current tenant and MustBeBusinessDeveloper (which returns early on null) still refuses
            // an admin, a suspended account, or anyone who does not carry leads.
            'assigned_to' => ['present', 'nullable', 'integer', OrgUserRule::exists(), new MustBeBusinessDeveloper],
        ];
    }
}
