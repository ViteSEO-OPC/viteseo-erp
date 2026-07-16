<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Models\PayslipRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePayslipRequestRequest extends FormRequest
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
            // A reason is required (must go through HR — brief §7.3).
            'reason' => ['required', 'string', 'max:1000'],
            // Must reference an existing payslip that belongs to the caller.
            'payslip_id' => [
                'required',
                'integer',
                Rule::exists('hr_payslips', 'id')
                    ->where('employee_id', $employeeId)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /** One live request per payslip: block a duplicate while one is pending or approved. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $employeeId = $this->user()?->employee?->id;
            $payslipId = $this->input('payslip_id');

            if (! $employeeId || ! $payslipId) {
                return;
            }

            $duplicate = PayslipRequest::where('employee_id', $employeeId)
                ->where('payslip_id', $payslipId)
                ->whereIn('status', ['pending', 'approved'])
                ->exists();

            if ($duplicate) {
                $validator->errors()->add(
                    'payslip_id',
                    'You already have a pending or approved hard-copy request for this payslip.',
                );
            }

            // Can only request a signed hard copy of a finalized (viewable) payslip.
            $finalized = \App\Modules\Hr\Models\Payslip::where('id', $payslipId)
                ->whereHas('payrollRun', fn ($q) => $q->where('status', 'completed'))
                ->exists();

            if (! $finalized) {
                $validator->errors()->add(
                    'payslip_id',
                    'This payslip is not finalized yet, so a hard copy cannot be requested.',
                );
            }
        });
    }
}
