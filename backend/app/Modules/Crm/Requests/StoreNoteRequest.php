<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route authorizes: ->can('view', 'lead') + permission:lead.notes.create.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Body is trimmed by the global TrimStrings middleware, so 'required' rejects whitespace-only.
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }
}
