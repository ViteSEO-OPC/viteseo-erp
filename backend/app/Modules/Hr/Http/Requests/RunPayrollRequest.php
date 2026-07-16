<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Services\PayScheduleService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class RunPayrollRequest extends FormRequest
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
            'cycle_start' => ['required', 'date'],
            'cycle_end' => ['required', 'date', 'after_or_equal:cycle_start'],
        ];
    }

    /**
     * The dates must match a REAL pay period for the frequency (not an arbitrary range)
     * and the period must have started — you can't run a future period.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $frequency = $this->input('frequency');
            $start = $this->input('cycle_start');
            $end = $this->input('cycle_end');

            if (! $frequency || ! $start || ! $end) {
                return; // base rules report the missing/invalid fields
            }

            $schedule = app(PayScheduleService::class);
            $anchor = PayrollSetting::current()->anchor_date;

            if (! $schedule->isAlignedPeriod($frequency, $anchor, $start, $end)) {
                $validator->errors()->add('cycle_start', 'These dates are not a valid pay period for the selected frequency.');

                return;
            }

            $today = now(config('app.timezone_local'))->startOfDay();
            if (Carbon::parse($start)->startOfDay()->gt($today)) {
                $validator->errors()->add('cycle_start', 'You cannot run payroll for a period that has not started yet.');
            }
        });
    }
}
