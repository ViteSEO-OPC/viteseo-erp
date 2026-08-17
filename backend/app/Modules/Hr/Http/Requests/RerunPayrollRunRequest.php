<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Recompute a period that has already been run.
 *
 * ONE FIELD, AND IT IS THE ONE THE OLD ROUTE COULD NOT ASK FOR. `POST payroll/run` takes a cycle and
 * a frequency, which is right for a period being run for the first time: nothing existed before, so
 * there is nothing to explain. Re-running is different in kind — it reprices a period that has been
 * settled, moving figures an employee may already hold a payslip for, and often only some of them
 * because the disbursed slips are left alone.
 *
 * The cycle is deliberately NOT accepted here: it comes from the run in the route, so a period cannot
 * be recomputed as a different pay group by mistyping a date.
 */
class RerunPayrollRunRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why this period is being recomputed — the figures it produces replace ones that were already settled.',
        ];
    }
}
