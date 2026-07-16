<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MoveCandidateRequest extends FormRequest
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
        // The stage is validated against the requisition's pipeline in the service.
        // Hiring is a separate action (POST .../hire) — not a plain stage move.
        return [
            'stage' => ['required', 'string', 'max:50'],
        ];
    }
}
