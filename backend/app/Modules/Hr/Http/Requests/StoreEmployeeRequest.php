<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Http\Requests\Concerns\EmployeeProfileRules;
use App\Modules\Hr\Services\EmployeeService;
use App\Support\CurrentOrg;
use App\Support\Entitlements;
use App\Support\GrantableRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEmployeeRequest extends FormRequest
{
    use EmployeeProfileRules;

    public function authorize(): bool
    {
        return true; // route is gated by role:hr_officer
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Identity (users.name is composed from the split-name fields).
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'name_extension' => ['nullable', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            // Optional: the controller generates one when it is absent. Either way
            // the value comes back ONCE in the response so HR can hand it over.
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            // Job fields.
            'department_id' => ['required', 'integer', 'exists:hr_departments,id'],
            'manager_id' => ['nullable', 'integer', 'exists:hr_employees,id'],
            'job_title' => ['required', 'string', 'max:255'],
            'employment_status' => ['required', Rule::in(EmployeeService::STATUSES)],
            'hire_date' => ['required', 'date'],
            'base_salary' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            // Interns only (checked in after()). Null means the company default.
            'allowance_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'ojt_required_hours' => ['nullable', 'integer', 'min:1', 'max:2000'],

            /*
             * Access in the other modules, granted as the person is hired.
             *
             * A closed list, not "any role": this endpoint is reachable by
             * hr_officer, who has no business minting a platform owner or another
             * module's admin. See GrantableRoles.
             */
            'grant_roles' => ['sometimes', 'array'],
            'grant_roles.*' => ['string', Rule::in(GrantableRoles::all())],

            ...$this->profileRules(),
        ];
    }

    /**
     * Two things the allowlist alone cannot say: whether the org bought the module,
     * and whether this person's employment lets them hold that tier.
     */
    public function after(): array
    {
        return [
            // An allowance and practicum hours describe an internship, and nothing else.
            function (Validator $validator): void {
                if ($this->input('employment_status') === 'ojt') {
                    return;
                }

                foreach ([
                    'allowance_amount' => 'Only an intern has an allowance.',
                    'ojt_required_hours' => 'Only an intern has required practicum hours.',
                ] as $field => $message) {
                    if ($this->filled($field)) {
                        $validator->errors()->add($field, $message);
                    }
                }
            },
            function (Validator $validator): void {
                $orgId = CurrentOrg::id();
                $status = (string) $this->input('employment_status');

                // Indexed keys, matching what `grant_roles.*` produces above: the
                // caller gets one consistent shape whichever rule refused, and the
                // form can mark the offending row rather than the whole field.
                foreach (array_values((array) $this->input('grant_roles', [])) as $i => $role) {
                    $module = GrantableRoles::moduleFor((string) $role);

                    if ($module === null) {
                        continue; // the Rule::in above already refused it
                    }

                    if ($orgId !== null && ! Entitlements::has($orgId, $module)) {
                        $validator->errors()->add(
                            "grant_roles.{$i}",
                            "This client has not availed {$module}, so there is nothing to give access to.",
                        );

                        continue;
                    }

                    if (GrantableRoles::isNarrow($status) && $role !== GrantableRoles::narrowestFor($module)) {
                        $validator->errors()->add(
                            "grant_roles.{$i}",
                            "A {$status} may only hold " . GrantableRoles::narrowestFor($module) . " in {$module}.",
                        );
                    }
                }
            },
        ];
    }
}
