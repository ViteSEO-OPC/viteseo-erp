<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpsertDailyOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->isMethod('get')) {
            return ['date' => ['nullable', 'date_format:Y-m-d']];
        }

        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'grace_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
        ];
    }
}
