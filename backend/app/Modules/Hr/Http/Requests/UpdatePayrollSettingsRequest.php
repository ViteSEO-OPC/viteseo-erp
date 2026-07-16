<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\PayScheduleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePayrollSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route gated by role:hr_officer
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'frequency' => ['required', Rule::in(array_keys(PayScheduleService::PERIODS_PER_YEAR))],
            'deduction_timing' => ['nullable', Rule::in(['every', 'first', 'last'])],
            'tax_timing' => ['nullable', Rule::in(['every', 'first', 'last'])],
            'anchor_date' => ['nullable', 'date'],
            'pay_delay_days' => ['nullable', 'integer', 'min:0', 'max:31'],
        ];
    }
}
