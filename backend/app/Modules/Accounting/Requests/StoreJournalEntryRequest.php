<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Support\CurrentOrg;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is enforced by the controller policy check.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'entry_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string', 'max:1000'],
            // Double-entry: at least two lines. Debit/credit balance is enforced in
            // JournalService (the whole entry is rejected if they don't match).
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => [
                'required',
                'integer',
                Rule::exists('acc_accounts', 'id')->where(
                    fn ($q) => $q->where('org_id', CurrentOrg::id())->whereNull('deleted_at'),
                ),
            ],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
            // When true, the entry is posted immediately (not left as a draft).
            'post' => ['nullable', 'boolean'],
        ];
    }
}
