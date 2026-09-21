<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\PayScheduleService;
use App\Modules\Hr\Support\InternPreviewScenario;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Company → Intern Allowance → Calculation Preview: the settings as they stand on the page (not
 * saved), and an invented month to price under them.
 */
class PreviewInternAllowanceSettingsRequest extends FormRequest
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
        $dayCount = ['sometimes', 'integer', 'min:0', 'max:31'];
        $counts = [];
        foreach ([...InternPreviewScenario::DAY_COUNTS, 'rest_days_worked'] as $key) {
            $counts["sample.{$key}"] = $dayCount;
        }

        return [
            ...UpdateInternAllowanceSettingsRequest::settingsRules(),

            'sample' => ['required', 'array'],
            'sample.frequency' => ['sometimes', Rule::in(array_keys(PayScheduleService::PERIODS_PER_YEAR))],
            'sample.amount_override' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'sample.scheduled_days' => ['required', 'integer', 'min:1', 'max:31'],
            'sample.hours_per_day' => ['sometimes', 'numeric', 'min:0.5', 'max:24'],
            ...$counts,
            'sample.suspensions_paid' => ['sometimes', 'boolean'],
            'sample.leave_type_paid' => ['sometimes', 'boolean'],
            'sample.late_minutes' => ['sometimes', 'array', 'max:31'],
            'sample.late_minutes.*' => ['integer', 'min:0', 'max:1440'],
            'sample.undertime_minutes' => ['sometimes', 'array', 'max:31'],
            'sample.undertime_minutes.*' => ['integer', 'min:0', 'max:1440'],
            'sample.hours_rendered' => ['sometimes', 'array', 'max:31'],
            'sample.hours_rendered.*' => ['numeric', 'min:0', 'max:24'],
            'sample.overtime_minutes' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'sample.night_minutes' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];
    }

    /** The kinds of day cannot add up to more workdays than the sample month has. */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $sample = (array) $this->input('sample', []);
                $used = array_sum(array_map(fn ($key) => max(0, (int) ($sample[$key] ?? 0)), InternPreviewScenario::DAY_COUNTS));

                if ($used > (int) ($sample['scheduled_days'] ?? 0)) {
                    $validator->errors()->add(
                        'sample.scheduled_days',
                        "The days listed add up to {$used}, more than the {$sample['scheduled_days']} scheduled.",
                    );
                }
            },
        ];
    }
}
