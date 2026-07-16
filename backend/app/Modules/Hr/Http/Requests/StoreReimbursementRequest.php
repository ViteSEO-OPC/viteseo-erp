<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\ReimbursementService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReimbursementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth:sanctum on the route; ownership from token
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(ReimbursementService::CATEGORIES)],
            'amount' => ['required', 'numeric', 'min:0'],
            'expense_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:1000'],
            // Receipt optional; when present, image/pdf up to 5 MB (§5.5e).
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }
}
