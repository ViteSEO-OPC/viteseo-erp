<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Services\HolidayService;
use App\Modules\Hr\Services\PayrollService;
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
            // FIN-030b — proceed past a minimum-wage breach, with the reason on the record.
            'minimum_wage_ack' => ['sometimes', 'boolean'],
            'minimum_wage_ack_reason' => [
                'nullable',
                'required_if_accepted:minimum_wage_ack',
                'string',
                'max:255',
            ],
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

                return;
            }

            // Every holiday in the cycle must be classified before pay is computed: the type
            // decides the premium (regular 200% vs special 130% vs none), so running with an
            // unclassified date silently under-pays anyone who worked it. Blocking here is
            // also what keeps an un-typed holiday from ever reaching the pay engine.
            $unclassified = app(HolidayService::class)
                ->unclassifiedBetween(Carbon::parse($start), Carbon::parse($end));

            if ($unclassified !== []) {
                $list = collect($unclassified)
                    ->map(fn ($name, $date) => "{$date} ({$name})")
                    ->implode(', ');

                $validator->errors()->add('cycle_start', trans_choice(
                    '{1}A holiday in this period still needs to be classified before payroll can run: :list. Set its type in Company → Holidays.'
                    . '|[2,*]:count holidays in this period still need to be classified before payroll can run: :list. Set their types in Company → Holidays.',
                    count($unclassified),
                    ['count' => count($unclassified), 'list' => $list],
                ));
            }

            $this->assertMinimumWageSeen($validator, $frequency, $start, $end);
        });
    }

    /**
     * FIN-030b — a minimum-wage breach stops the run ONCE, and any answer gets past it.
     *
     * WHY THIS BLOCKS WHEN THE PROFILE BAND DOES NOT. The band informs whoever opens a profile,
     * which for a compliant-looking employee is nobody. This is the moment money is committed for
     * the period, and paying below the floor is not a preference — it creates wage differentials
     * and, under RA 8188, double indemnity. Letting that happen with nothing on the record is the
     * failure; the payment itself may well be correct.
     *
     * So it is a speed bump, not a wall. Everything the check cannot see — a granted exemption, a
     * part-time schedule, an order that does not apply — is still a legitimate reason to proceed,
     * and the acknowledgement carries the reason rather than refusing. The permanent version of
     * that answer is the per-employee exemption flag, which takes the person out of the check for
     * good; this is for the one-off.
     *
     * Silent when no floor is configured: `flagged_count` is 0, so nothing about today's behaviour
     * changes until somebody enters a wage order.
     */
    private function assertMinimumWageSeen(Validator $validator, string $frequency, string $start, string $end): void
    {
        if ((bool) $this->input('minimum_wage_ack')) {
            return;
        }

        $review = app(PayrollService::class)->minimumWageReview($frequency, $start, $end);

        if ($review['flagged_count'] === 0) {
            return;
        }

        $names = collect($review['employees'])
            ->reject(fn ($row) => $row['exempt'])
            ->map(fn ($row) => $row['employee']['name'])
            ->filter()
            ->take(5)
            ->implode(', ');

        $more = max(0, $review['flagged_count'] - 5);

        $validator->errors()->add('minimum_wage_ack', trans_choice(
            '{1}:names is paid below the ₱:minimum regional minimum daily wage for this period.'
            . '|[2,*]:count employees are paid below the ₱:minimum regional minimum daily wage for this period (:names:more).',
            $review['flagged_count'],
            [
                'count' => $review['flagged_count'],
                'minimum' => number_format((float) $review['minimum'], 2),
                'names' => $names,
                'more' => $more > 0 ? " and {$more} more" : '',
            ],
        ) . ' Raise them to the floor, mark them exempt, or confirm a reason to run anyway.');
    }
}
