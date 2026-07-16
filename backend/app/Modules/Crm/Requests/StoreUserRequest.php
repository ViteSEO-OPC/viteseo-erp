<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            // Only roles the creator may assign (Super Admin → admin/BDM, Admin → BDM). Never super-admin.
            'role' => ['required', Rule::in($this->user()->assignableRoles())],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            // Optional: admin may pre-generate a temp password; otherwise the server generates one.
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
        ];
    }
}
