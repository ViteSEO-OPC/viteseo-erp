<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** HR asking an anonymous reporter to identify themselves. */
class StoreConcernRevealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:hr_officer|super_admin on the route
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why you need to know who filed this — the reporter sees this and decides on it.',
        ];
    }
}
