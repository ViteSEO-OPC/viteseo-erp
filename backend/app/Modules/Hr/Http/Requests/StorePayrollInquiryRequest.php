<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePayrollInquiryRequest extends FormRequest
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
        $employeeId = $this->user()?->employee?->id;

        return [
            'subject' => ['required', 'string', 'max:255'],
            'topic' => ['required', 'string', 'max:100'],
            'message' => ['required', 'string', 'max:2000'],
            // Related pay period — must be one of the caller's own payslips (or none).
            'payslip_id' => [
                'nullable',
                'integer',
                Rule::exists('hr_payslips', 'id')->where('employee_id', $employeeId)->whereNull('deleted_at'),
            ],
            // Optional screenshot of the payslip.
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }
}
