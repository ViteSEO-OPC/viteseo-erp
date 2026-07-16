<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Used for both create and update (schedules have no unique field).
class StoreWorkScheduleRequest extends FormRequest
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
            'workdays' => ['required', 'array', 'min:1'],
            'workdays.*' => ['integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'grace_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'is_default' => ['boolean'],
        ];
    }
}
