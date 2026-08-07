<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Models\BenefitProgram;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'kind' => ['sometimes', Rule::in(BenefitProgram::KINDS)],
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            // Slice B5.4 — see withValidator(): an allowance must keep an amount. The check lives
            // there because `kind` may be absent on a PATCH and has to fall back to the saved row.
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'color' => ['sometimes', 'nullable', 'string', 'max:20'],
            'frequency' => ['sometimes', 'string', 'max:20'],
            'tax_treatment' => ['sometimes', 'in:de_minimis,taxable'],
            // Slice B16 — this benefit's OWN statutory ceiling. Null = none recorded, which keeps
            // the whole amount exempt. Ceilings are per item and are never pooled.
            // Slice B11 — the date this price applies from. Defaults to today; HR sets it
            // deliberately when a rise is agreed in arrears.
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'deminimis_ceiling' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999.99'],
            // Slice B15 — loan-policy config. Typed per kind rather than shared into one loose
            // shape: a loan policy and an HMO have nothing in common but a name.
            'loan_max_principal' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'loan_term_periods' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:600'],
            'ceiling_period' => ['sometimes', 'nullable', 'in:monthly,semester,annual'],
            // Who the programme is for. `status` carries the list below; `custom` carries
            // `employee_ids`. The old `regular` value is gone — it was the only status the model
            // could express, and is now `status` with one entry.
            'eligibility' => ['sometimes', Rule::in(BenefitProgram::ELIGIBILITY_MODES)],
            'eligibility_statuses' => ['sometimes', 'nullable', 'array'],
            'eligibility_statuses.*' => ['string', 'max:40'],
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
            // No `is_active` — see StoreBenefitProgramRequest. Deleting is the off switch.
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

            // Same guard as on create: a rule that covers nobody saves cleanly and pays no one.
            $mode = $this->input('eligibility') ?? $program?->eligibility;

            if ($mode === 'status' && $this->has('eligibility')
                && empty($this->input('eligibility_statuses'))) {
                $v->errors()->add(
                    'eligibility_statuses',
                    'Choose at least one employment status, or this allowance covers nobody.',
                );
            }

            if ($mode === 'custom' && $this->has('eligibility')
                && empty($this->input('employee_ids'))) {
                $v->errors()->add(
                    'employee_ids',
                    'Choose at least one employee, or this allowance covers nobody.',
                );
            }
        });
    }
}
