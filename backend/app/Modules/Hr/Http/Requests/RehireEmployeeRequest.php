<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Taking someone back as a new employment relationship. */
class RehireEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is behind auth:sanctum + the HR role group
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hire_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
