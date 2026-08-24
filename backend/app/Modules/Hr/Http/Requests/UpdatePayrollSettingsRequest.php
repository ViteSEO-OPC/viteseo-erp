<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Rules\AppliesFromToday;
use App\Modules\Hr\Services\PayScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePayrollSettingsRequest extends FormRequest
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
            // The date these settings take effect. Optional, and omitting it — which is what
            // the UI now does — means today. Kept as a field only so a rise can be SCHEDULED;
            // a past date is refused outright, because repricing a period that has already run
            // is a payslip correction's job, not a rule's. See AppliesFromToday.
            'effective_from' => ['sometimes', 'nullable', 'date', new AppliesFromToday],
            // The regional minimum daily wage from the applicable DOLE wage order.
            'minimum_daily_wage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999'],
            'frequency' => ['required', Rule::in(array_keys(PayScheduleService::PERIODS_PER_YEAR))],
            // NO `first` FOR CONTRIBUTIONS. A statutory contribution on `earnings` is a
            // monthly amount on a monthly base, and the first cutoff cannot know the month: it can
            // only ever be provisional, which means the last cutoff has to carry an adjustment —
            // and once it does, "first cutoff only" IS a split, just a worse-shaped one (the whole
            // month taken on the 15th and handed back on the 30th). Withholding tax and allowances
            // keep all three: tax self-corrects at the year-end true-up, and an allowance is a
            // fixed monthly figure with no base to discover.
            'deduction_timing' => ['nullable', Rule::in(['every', 'last'])],
            'tax_timing' => ['nullable', Rule::in(['every', 'first', 'last'])],
            // Same three values as the timings above, and the same meaning.
            'allowance_timing' => ['nullable', Rule::in(['every', 'first', 'last'])],
            'anchor_date' => ['nullable', 'date'],
            'pay_delay_days' => ['nullable', 'integer', 'min:0', 'max:31'],
            // Null = no ceiling, i.e. allowances stay entirely non-taxable.
            // ₱90,000 governs 13th month AND OTHER BENEFITS, not de minimis. Each
            // de-minimis item has its own ceiling, held on the benefit itself.
            'other_benefits_annual_cap' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ];
    }

    /**
     * Allowance timing cannot move part way through a month that has already been part-paid.
     *
     * Which cutoff the allowance lands on is decided per cutoff and never reconciled across the
     * month — unlike a statutory contribution, whose final cutoff settles up against what the
     * month has already taken (`contributionForPeriod`). So a mid-month switch mis-pays every
     * time, in whichever direction: `every`→`last` pays half the month and then a whole one
     * (1.5x), `every`→`first` and `last`→`every` pay only half, and `last`→`first` pays nothing
     * at all. Proven end-to-end in AllowanceTimingSwitchTest.
     *
     * Blocked rather than reconciled deliberately: HR asking for this mid-month means one of two
     * different things (fix a wrong setting, or change policy from now on), and guessing wrong
     * moves real money. The setting is free to change from the next month, and a month already
     * mis-paid is a payslip correction — which is the tool that can subtract.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $timing = $this->input('allowance_timing');

            // Absent, or the value it already holds — nothing is moving.
            if ($timing === null || $timing === PayrollSetting::current()->allowance_timing) {
                return;
            }

            // Day boundaries in Manila, per the platform rule. An omitted date means today,
            // which is what the UI sends now that a rule only ever applies forward.
            $effective = ($from = $this->input('effective_from'))
                ? CarbonImmutable::parse((string) $from, config('app.timezone_local'))
                : CarbonImmutable::now(config('app.timezone_local'));

            // A cutoff of the SAME month, already computed, that ended before this change. Keyed
            // on cycle_end because that is the cutoff the allowance lands on — a fortnight
            // straddling two months belongs to the month it ends in.
            $alreadyRun = PayrollRun::query()
                ->whereDate('cycle_end', '>=', $effective->startOfMonth()->toDateString())
                ->whereDate('cycle_end', '<', $effective->toDateString())
                ->whereNotIn('status', ['draft', 'scheduled'])
                ->orderByDesc('cycle_end')
                ->first();

            if ($alreadyRun === null) {
                return;
            }

            $v->errors()->add('allowance_timing', sprintf(
                'The %s cutoff of this month has already been run with allowances set to "%s", '
                .'so changing it now would mis-pay %s allowance. Change it from %s onward, and '
                .'correct the affected payslips if this month is already wrong.',
                CarbonImmutable::parse($alreadyRun->cycle_end)->format('j F'),
                str_replace('_', ' ', (string) PayrollSetting::current()->allowance_timing),
                $effective->format('F') . "'s",
                $effective->startOfMonth()->addMonth()->format('j F Y'),
            ));
        });
    }
}
