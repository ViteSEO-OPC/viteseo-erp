<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit an account's name / active flag. Code + type stay immutable — changing
 * them would misclassify or orphan existing ledger postings.
 */
class UpdateAccountRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            // `type` is immutable, so the account being edited is the authority
            // on whether this is allowed.
            'is_bank' => [
                'nullable',
                'boolean',
                function (string $attribute, mixed $value, callable $fail): void {
                    $account = $this->route('account');

                    if ($this->boolean('is_bank') && $account?->type->value !== 'asset') {
                        $fail('Only an asset account can be a bank account.');
                    }
                },
            ],
        ];
    }
}
