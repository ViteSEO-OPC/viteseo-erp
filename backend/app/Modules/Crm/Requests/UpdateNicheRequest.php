<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Models\Niche;
use App\Modules\Crm\Rules\UniqueNicheName;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNicheRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @see StoreNicheRequest::prepareForValidation() */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $niche = $this->route('niche');

        return [
            // Ignores the row being renamed, so re-saving a niche under its own name (or only
            // changing its casing) is not reported as a duplicate of itself.
            'name' => [
                'required', 'string', 'max:255',
                new UniqueNicheName($niche instanceof Niche ? $niche->getKey() : null),
            ],
        ];
    }
}
