<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Support\CurrentOrg;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBillRequest extends FormRequest
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
            'contact_id' => [
                'required',
                'integer',
                Rule::exists('acc_contacts', 'id')->where(
                    fn ($q) => $q->where('org_id', CurrentOrg::id())->whereNull('deleted_at'),
                ),
            ],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'vat_class' => ['nullable', 'in:vatable,exempt'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.account_id' => [
                'required',
                'integer',
                Rule::exists('acc_accounts', 'id')->where(
                    fn ($q) => $q->where('org_id', CurrentOrg::id())->whereNull('deleted_at'),
                ),
            ],
        ];
    }
}
