<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Same assignable-role constraint as create (Admin can only set BDM).
            'role' => ['sometimes', Rule::in($this->user()->assignableRoles())],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
