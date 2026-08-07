<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Models\BenefitProgram;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBenefitProgramRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'max:50'],
            'kind' => ['sometimes', Rule::in(BenefitProgram::KINDS)],
            // Slice B5.4 — required for an allowance (it would otherwise pay ₱0 silently);
            // nullable for a company plan, which prices by tier instead.
            'amount' => ['required_if:kind,allowance', 'nullable', 'numeric', 'min:0'],
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
            // Slice B19 — whether this allowance counts toward the statutory contribution base.
            // Separate from `tax_treatment`: a taxable allowance can sit outside the SSS base and
            // the mandated COLA is de-minimis for tax but inside it. Defaults to counting.
            'contributable' => ['sometimes', 'boolean'],
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
            'status' => ['sometimes', 'string', 'max:50'],
            'details' => ['array'],
            'details.*.label' => ['required', 'string', 'max:100'],
            'details.*.value' => ['required', 'string', 'max:100'],
            'progress' => ['nullable', 'array'],
            'progress.percent' => ['required_with:progress', 'numeric', 'min:0', 'max:100'],
            'progress.caption' => ['nullable', 'string', 'max:120'],
            'footer_label' => ['nullable', 'string', 'max:60'],
            // No `is_active` — B13 item 4 left a soft delete as the only way to switch a
            // programme off, so that it is always reversible from the screen that did it.
            'employee_ids' => ['array'],
            'employee_ids.*' => ['integer', 'exists:hr_employees,id'],
        ];
    }

    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function ($v) {
            // Slice B5.4 — an allowance with no amount renders as ₱0 and pays ₱0, with nothing to
            // say it was never finished. `amount` stays nullable for company plans (HMO), which
            // price by tier rather than by a single figure.
            $kind = $this->input('kind', 'allowance');
            if ($kind === 'allowance' && $this->has('amount') && $this->input('amount') === null) {
                $v->errors()->add('amount', 'An allowance needs an amount — it would pay nothing.');
            }

            $this->validateEligibility($v);
        });
    }

    /**
     * A rule that covers nobody is the failure mode worth blocking: the allowance saves cleanly,
     * appears on the page, and quietly pays no one.
     */
    protected function validateEligibility(\Illuminate\Contracts\Validation\Validator $v): void
    {
        $mode = $this->input('eligibility');

        if ($mode === 'status' && empty($this->input('eligibility_statuses'))) {
            $v->errors()->add(
                'eligibility_statuses',
                'Choose at least one employment status, or this allowance covers nobody.',
            );
        }

        if ($mode === 'custom' && empty($this->input('employee_ids'))) {
            $v->errors()->add(
                'employee_ids',
                'Choose at least one employee, or this allowance covers nobody.',
            );
        }
    }
}
