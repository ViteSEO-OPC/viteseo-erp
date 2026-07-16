<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexEmailRequest extends FormRequest
{
    /** Statuses the email index may filter by. v1 exposes drafts only (email history is later). */
    private const STATUSES = ['draft'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(self::STATUSES)],
        ];
    }
}
