<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarkLeadLostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Optional lost reason — stored in the lead.lost Activity's `properties` JSON (no schema column).
            'reason' => ['sometimes', 'nullable', Rule::in(['competitor', 'budget', 'no-response', 'not-interested', 'other'])],
        ];
    }
}
