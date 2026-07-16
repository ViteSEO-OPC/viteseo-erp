<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\HolidayService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHolidayRequest extends FormRequest
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
            'date' => ['required', 'date'],
            'type' => ['required', Rule::in(HolidayService::TYPES)],
        ];
    }
}
