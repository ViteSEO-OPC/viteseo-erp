<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The payload for every "undo this mark" endpoint. HR may explain why, and the reason
 * is recorded on the audit entry; it is never required, because the common case is
 * correcting a misclick.
 */
class ReopenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware already restricts this to HR
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
