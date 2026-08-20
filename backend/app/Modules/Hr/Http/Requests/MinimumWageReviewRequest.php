<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Services\PayScheduleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Which pay group and which cycle to judge against the floor. */
class MinimumWageReviewRequest extends FormRequest
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
            'frequency' => ['required', Rule::in(array_keys(PayScheduleService::PERIODS_PER_YEAR))],
            'cycle_start' => ['sometimes', 'required_with:cycle_end', 'date'],
            'cycle_end' => ['sometimes', 'required_with:cycle_start', 'date', 'after_or_equal:cycle_start'],
        ];
    }

    /**
     * @return array{frequency: string, cycle_start: string, cycle_end: string}
     */
    public function cycle(): array
    {
        $frequency = $this->validated('frequency');
        $start = $this->validated('cycle_start');
        $end = $this->validated('cycle_end');

        if ($start === null || $end === null) {
            $period = app(PayScheduleService::class)
                ->currentPeriodFor($frequency, PayrollSetting::current()->anchor_date);

            $start = $period['start'];
            $end = $period['end'];
        }

        return ['frequency' => $frequency, 'cycle_start' => $start, 'cycle_end' => $end];
    }
}
