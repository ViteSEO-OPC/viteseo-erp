<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Rules\UniqueNicheName;
use Illuminate\Foundation\Http\FormRequest;

class StoreNicheRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Trim before validating so " Retail " cannot slip past the unique rule as a distinct value
     * and land in the registry with padding that the selector would then render.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Case-insensitive by explicit comparison, not by database collation — see UniqueNicheName.
            'name' => ['required', 'string', 'max:255', new UniqueNicheName],
        ];
    }
}
