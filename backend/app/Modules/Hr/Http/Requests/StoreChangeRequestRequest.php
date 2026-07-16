<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\ChangeRequestService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChangeRequestRequest extends FormRequest
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
        return [
            // One or more field changes; each becomes its own request for HR to review.
            'changes' => ['required', 'array', 'min:1', 'max:28'],
            'changes.*.field' => ['required', Rule::in(array_keys(ChangeRequestService::FIELDS))],
            'changes.*.new_value' => ['required', 'string', 'max:255'],
        ];
    }
}
