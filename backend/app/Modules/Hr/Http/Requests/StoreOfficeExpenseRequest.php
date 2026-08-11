<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\OfficeExpenseService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOfficeExpenseRequest extends FormRequest
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
            'category' => ['required', Rule::in(OfficeExpenseService::CATEGORIES)],
            'amount' => ['required', 'numeric', 'min:0'],
            'expense_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:1000'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            // Optional: omitted means "leave the review state alone" (a new expense starts
            // `recorded`). The service routes any change through the same approve/reject/reopen
            // path the action buttons use, so reviewer, timestamp and audit line stay correct.
            'status' => ['nullable', Rule::in(OfficeExpenseService::STATUSES)],
            'review_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
