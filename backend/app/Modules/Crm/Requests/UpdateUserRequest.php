<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /users/{user} — edit a CRM team account.
 *
 * `role` is OPTIONAL and, when present, may be `null`, which means **no CRM access**: the user keeps
 * their account and every non-CRM role, and simply stops being a CRM user. Omitting the key entirely
 * leaves the role pivot untouched — that is what makes a rename or a suspend a pure account edit
 * with no role write, which existing live BD/Admin accounts depend on.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            // Same assignable-role constraint as create (Admin can only set BDM) — `nullable` adds
            // the explicit "no CRM access" choice without widening WHICH roles may be assigned, so
            // an Admin still cannot mint or revoke another Admin.
            'role' => ['sometimes', 'nullable', Rule::in($this->user()->assignableRoles())],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
