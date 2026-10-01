<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Rules\AppliesFromToday;
use App\Modules\Hr\Support\InternAllowancePolicy;
use App\Support\CurrentOrg;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Company → Intern Allowance. A full replacement: the page sends every setting. */
class UpdateInternAllowanceSettingsRequest extends FormRequest
{
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
            // Dates the VERSION payroll resolves against. Today or later: a period already run is
            // corrected on its payslips, not by rewriting the rules it ran under.
            // `date` alone accepts "2026-10-01T00:00:00.000Z", "10/01/2026" and "next friday". This
            // string is the version's KEY — updateOrCreate() matches it against a DATE column — so
            // anything but Y-m-d misses the row it meant to correct and collides on the unique index
            // instead. It also decides the day boundary: Carbon ignores the Manila timezone for a
            // string that carries its own offset, so an ISO-Z value would be judged in UTC, which is
            // the opposite of what AppliesFromToday exists to enforce.
            'effective_from' => ['sometimes', 'nullable', 'date_format:Y-m-d', new AppliesFromToday],
            // Set once HR has seen which interns' own allowances a change of unit re-reads.
            'confirm_override_reinterpretation' => ['sometimes', 'boolean'],
            ...self::settingsRules(),
        ];
    }

    /**
     * The settings themselves, shared with the calculation preview so the preview can never
     * accept a combination a save would refuse.
     *
     * @return array<string, mixed>
     */
    public static function settingsRules(): array
    {
        $rules = [];
        foreach (InternAllowancePolicy::OPTIONS as $key => $options) {
            $rules[$key] = ['required', Rule::in($options)];
        }

        $money = ['numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'];
        $charge = ['numeric', 'min:0.01', 'max:9999999.99', 'decimal:0,2'];
        $hours = ['numeric', 'min:0.5', 'max:24'];
        // A premium rate is the whole day's pay as a percentage of the plain allowance (130 =
        // allowance plus 30%). Under 100 would pay LESS for working a premium day.
        $premiumPct = ['numeric', 'min:100', 'max:500'];

        foreach (array_keys(InternAllowancePolicy::PREMIUMS) as $prefix) {
            $rules["{$prefix}_premium_pct"] = [
                "required_if:{$prefix}_premium_source,custom", 'nullable', ...$premiumPct,
            ];
        }

        return $rules + [
            'required_hours_per_day' => ['required', ...$hours],
            'daily_amount' => ['required_if:method,per_day', 'nullable', ...$money],
            'hourly_amount' => ['required_if:method,per_hour', 'nullable', ...$money],
            'max_paid_hours_per_day' => ['required_if:method,per_hour', 'nullable', ...$hours],
            'monthly_amount' => ['required_if:method,fixed_monthly', 'nullable', ...$money],

            'late_fixed_amount' => ['required_if:late_rule,fixed_amount', 'nullable', ...$charge],
            'late_partial_after_minutes' => ['required_if:late_rule,partial_after_minutes', 'nullable', 'integer', 'min:1', 'max:480'],
            'undertime_fixed_amount' => ['required_if:undertime_rule,fixed_amount', 'nullable', ...$charge],

            // Night differential is an add-on to the hourly allowance, not a whole rate.
            'night_diff_pct' => ['required_if:night_diff_rule,custom', 'nullable', 'numeric', 'min:0', 'max:100'],
            'holiday_forfeit_after_absence' => ['required', 'boolean'],

            // Per day: hours rendered that day ÷ required hours. 0 switches the check off.
            'min_attendance_pct' => ['required', 'integer', 'min:0', 'max:100'],

            'leave_type_ids' => ['required_if:leave_types_allowed,selected', 'array'],
            'leave_type_ids.*' => [
                'integer',
                Rule::exists('hr_leave_types', 'id')->where('org_id', CurrentOrg::id())->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * The amount the chosen method actually reads has to be a real figure. `min:0` alone lets a
     * company publish a ₱0 allowance, and then every intern prices at zero — silently, with nothing
     * anywhere saying so. Checked on SAVE only: the preview shares settingsRules() and is meant to
     * price a half-typed page.
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $field = match ($this->input('method')) {
                    'per_hour' => 'hourly_amount',
                    'fixed_monthly' => 'monthly_amount',
                    default => 'daily_amount',
                };

                // Let the existing required_if/numeric failure speak for itself rather than stack on it.
                if ($validator->errors()->has($field)) {
                    return;
                }

                if ((float) $this->input($field) <= 0) {
                    $validator->errors()->add(
                        $field,
                        'Enter the allowance interns are paid. Zero would pay every intern nothing.',
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'leave_type_ids.required_if' => 'Pick at least one leave type interns may file.',
            '*.required_if' => 'Required for the option selected.',
        ];
    }
}
