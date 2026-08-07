<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Models\ContributionVersion;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Slice B3a — a new dated version of a statutory contribution.
 *
 * Every field is validated on its own. The old endpoint took `'config' => ['sometimes','array']`,
 * so a typo (`raet` instead of `rate`) saved cleanly and silently zeroed that deduction on every
 * payslip. Unknown keys are now impossible: the request is a fixed shape, not a free-form blob.
 */
class StoreContributionVersionRequest extends FormRequest
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
            'type' => ['required', Rule::in(ContributionVersion::TYPES)],
            'effective_from' => ['required', 'date'],
            'basis' => ['sometimes', Rule::in(ContributionVersion::BASES)],
            // Slice B19 — WHICH salary figure the rate applies to, as opposed to `basis` above,
            // which is how the charge is worked out once a figure is chosen. Omitted means
            // `basic`, the column default and what every version recorded before B19 did.
            'salary_basis' => ['sometimes', Rule::in(ContributionVersion::SALARY_BASES)],

            // Rates as decimals (0.05 = 5%). Capped at 100% — a rate above that is a data-entry
            // slip, not a policy, and would wipe out net pay.
            'employee_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'employer_rate' => ['required', 'numeric', 'min:0', 'max:1'],

            'salary_floor' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'salary_cap' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'employee_amount_cap' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'employer_amount_cap' => ['nullable', 'numeric', 'min:0', 'max:99999999'],

            'fixed_employer_add_on' => ['sometimes', 'numeric', 'min:0', 'max:99999'],
            'add_on_min_salary' => ['nullable', 'numeric', 'min:0', 'max:99999999'],

            'note' => ['nullable', 'string', 'max:255'],

            // FIN-026 — HR's explicit acknowledgement that this date reaches back over payroll
            // runs already completed. The service refuses without it and names the runs, so this
            // can only be sent by someone who has been told what it affects.
            'acknowledge_repricing' => ['sometimes', 'boolean'],

            // Slice B3b — MSC bands, lowest first. Each runs up to the next band's `min_salary`,
            // so there is no `max` to keep in step.
            'brackets' => ['sometimes', 'array', 'max:200'],
            'brackets.*.min_salary' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'brackets.*.msc' => ['required', 'numeric', 'min:0', 'max:99999999'],
            // Slice B8 — the employer-only EC printed for this band. Null falls back to the
            // version's `fixed_employer_add_on` rule.
            'brackets.*.employer_add_on' => ['nullable', 'numeric', 'min:0', 'max:99999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $floor = $this->input('salary_floor');
            $cap = $this->input('salary_cap');

            // A floor above the cap clamps every salary to the cap and silently charges everyone
            // the same wrong amount.
            if (is_numeric($floor) && is_numeric($cap) && (float) $floor > (float) $cap) {
                $v->errors()->add('salary_floor', 'The salary floor cannot be higher than the salary ceiling.');
            }

            // An add-on threshold with no add-on (or the reverse) means one of the two was
            // forgotten — both are needed for the rule to do anything.
            $addOn = (float) ($this->input('fixed_employer_add_on') ?? 0);
            if ($addOn > 0 && $this->input('add_on_min_salary') === null) {
                $v->errors()->add(
                    'add_on_min_salary',
                    'Set the salary from which the employer add-on applies, or leave the add-on at 0.',
                );
            }

            // Slice B3b — a bracket version needs bands, and they must start at 0 so that every
            // salary lands in one. A band list starting above 0 would leave the lowest earners
            // with no bracket and no contribution at all.
            $brackets = $this->input('brackets');
            if ($this->input('basis') === 'bracket') {
                if (! is_array($brackets) || $brackets === []) {
                    $v->errors()->add('brackets', 'A bracket-based contribution needs at least one salary band.');
                } else {
                    $mins = array_map(fn ($b) => (float) ($b['min_salary'] ?? -1), $brackets);
                    if (min($mins) > 0) {
                        $v->errors()->add('brackets', 'The lowest band must start at 0, so every salary falls into a bracket.');
                    }
                    if (count($mins) !== count(array_unique($mins))) {
                        $v->errors()->add('brackets', 'Two bands start at the same salary — each band must begin where the previous one ends.');
                    }
                }
            } elseif (is_array($brackets) && $brackets !== []) {
                $v->errors()->add('brackets', 'Salary bands only apply when the basis is set to bracket.');
            }
        });
    }
}
