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
            // Assign / reassign a lead to a BD. Route is guarded by leads.assign (Admin/SA).
            'assigned_to' => ['required', 'integer', OrgUserRule::exists(), new MustBeBusinessDeveloper],
        ];
    }
}
