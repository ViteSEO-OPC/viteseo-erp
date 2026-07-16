<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkLeadWonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Realized deal value. Optional — defaults to the lead's estimate (leads.amount) when omitted.
            // This is the ONLY writer of a Deal amount from this action; Revenue = Σ deals.amount.
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }
}
