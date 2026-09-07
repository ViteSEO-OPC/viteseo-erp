<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Support\CurrentOrg;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
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
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('acc_accounts', 'code')->where(
                    fn ($q) => $q->where('org_id', CurrentOrg::id())->whereNull('deleted_at'),
                ),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:asset,liability,equity,income,expense'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('acc_accounts', 'id')->where(
                    fn ($q) => $q->where('org_id', CurrentOrg::id())->whereNull('deleted_at'),
                ),
            ],
            // A bank account is an asset. Leaving the question open would let
            // Salaries Expense be offered as an answer to "which bank statement
            // is this?" -- which is the shape of the bug this flag exists to fix.
            'is_bank' => [
                'nullable',
                'boolean',
                function (string $attribute, mixed $value, callable $fail): void {
                    if ($this->boolean('is_bank') && $this->input('type') !== 'asset') {
                        $fail('Only an asset account can be a bank account.');
                    }
                },
            ],
        ];
    }
}
