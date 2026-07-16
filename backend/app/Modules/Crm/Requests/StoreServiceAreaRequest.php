<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Models\ServiceArea;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is role-guarded (super-admin|admin)
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'level' => ['required', Rule::in(ServiceArea::LEVELS)],
        ];
    }
}
