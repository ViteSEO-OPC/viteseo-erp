<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreHolidaySetRequest extends FormRequest
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
            'country_code' => ['required', 'string', 'size:2', 'alpha'],
            'year' => ['required', 'integer', 'between:2000,2100'],
        ];
    }
}
