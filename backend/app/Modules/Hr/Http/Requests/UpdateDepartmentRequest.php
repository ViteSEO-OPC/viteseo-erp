<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // {department} is route-model-bound, so grab its id to ignore itself
        // in the unique check (otherwise updating without changing code fails).
        $departmentId = $this->route('department')->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('hr_departments', 'code')->ignore($departmentId),
            ],
            'is_active' => ['boolean'],
        ];
    }
}
