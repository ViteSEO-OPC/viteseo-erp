<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBenefitProgramRequest extends FormRequest
{
    // Authorization is handled by route middleware (auth:sanctum + role:hr_officer).
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'required', 'string', 'max:50'],
            'kind' => ['sometimes', 'in:allowance,company'],
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            // Slice B5.4 — see withValidator(): an allowance must keep an amount. The check lives
            // there because `kind` may be absent on a PATCH and has to fall back to the saved row.
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'color' => ['sometimes', 'nullable', 'string', 'max:20'],
            'frequency' => ['sometimes', 'string', 'max:20'],
            'tax_treatment' => ['sometimes', 'in:de_minimis,taxable'],
            'eligibility' => ['sometimes', 'in:all,regular,custom'],
            'deduction_cadence' => ['sometimes', 'nullable', 'in:per_cutoff,monthly'],
            'tiers' => ['sometimes', 'nullable', 'array'],
            'tiers.*.name' => ['required', 'string', 'max:40'],
            'tiers.*.monthly_premium' => ['required', 'numeric', 'min:0'],
            'tiers.*.employee_share' => ['required', 'numeric', 'min:0'],
            'status' => ['sometimes', 'required', 'string', 'max:50'],
            'details' => ['sometimes', 'array'],
            'details.*.label' => ['required', 'string', 'max:100'],
            'details.*.value' => ['required', 'string', 'max:100'],
            'progress' => ['sometimes', 'nullable', 'array'],
            'progress.percent' => ['required_with:progress', 'numeric', 'min:0', 'max:100'],
            'progress.caption' => ['nullable', 'string', 'max:120'],
            'footer_label' => ['sometimes', 'nullable', 'string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
            'employee_ids' => ['sometimes', 'array'],
            'employee_ids.*' => ['integer', 'exists:hr_employees,id'],
        ];
    }

    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function ($v) {
            // Slice B5.4 — an allowance with no amount renders as ₱0 and pays ₱0, with nothing to
            // say it was never finished. On a PATCH `kind` is usually absent, so the saved row
            // decides: clearing the amount of an existing allowance must fail too.
            $program = $this->route('benefitProgram');
            $kind = $this->input('kind') ?? $program?->kind ?? 'allowance';
            if ($kind === 'allowance' && $this->has('amount') && $this->input('amount') === null) {
                $v->errors()->add('amount', 'An allowance needs an amount — it would pay nothing.');
            }
        });
    }
}
