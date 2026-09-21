<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Support\InternAllowancePolicy;
use App\Modules\Hr\Support\InternDay;
use App\Modules\Hr\Support\InternPricing;
use App\Modules\Hr\Support\InternRateContext;

/**
 * Prices an intern's allowance for a run of days under Company → Intern Allowance.
 *
 * No queries and no clock: every fact arrives as an InternDay, every rule as the policy, and the
 * company's own premium rates as the attendance policy in force. That is what lets the settings
 * page preview a month through exactly the code that pays one.
 *
 * Two shapes of pay. An allowance per day or per month is DEDUCTION-based, like a salary: the
 * period's allowance is shown in full and each thing attendance takes off it is a charge line, so
 * the payslip reads the way every other payslip does. An allowance per hour is EARNED: hours
 * rendered × the hourly allowance, with charge lines kept only as a record of what was not worked.
 *
 * Stateful for the length of one price() call and reset at the start of the next.
 */
class InternAllowancePricer
{
    private InternAllowancePolicy $rules;

    private InternRateContext $ctx;

    private AttendancePolicy $company;

    private string $method;

    private bool $hoursBased;

    /** False only for a fixed monthly allowance with no proration: days attended change nothing. */
    private bool $prorates;

    private int $requiredMinutes;

    private float $daily = 0.0;

    private float $hourly = 0.0;

    /** @var list<array{date: string, kind: string, days: float, detail: string|null, amount: float}> */
    private array $charges = [];

    /** @var array<string, float> days already charged per date — a day never costs more than itself */
    private array $chargedOn = [];

    /** @var list<array<string, mixed>> */
    private array $holidayLines = [];

    private float $holidayPay = 0.0;

    private float $restDayPay = 0.0;

    private int $restDayMinutes = 0;

    private float $overtimePay = 0.0;

    private int $overtimeMinutes = 0;

    private float $nightPay = 0.0;

    private int $nightMinutes = 0;

    /** The per-hour allowance earned on ordinary days. */
    private float $hourlyBase = 0.0;

    private int $workingDays = 0;

    private int $renderedMinutes = 0;

    private int $paidMinutes = 0;

    private int $lateMinutes = 0;

    private int $undertimeMinutes = 0;

    private int $lateCount = 0;

    /** @var list<array{date: string, code: string, detail: string|null}> */
    private array $flags = [];

    /**
     * Was the last workday an absence without pay? Null until a workday has been seen, which
     * leaves a regular holiday at the start of a range paid — the favourable reading of not knowing.
     */
    private ?bool $prevWorkdayUnpaid = null;

    /** @param  list<InternDay>  $days */
    public function price(array $days, InternAllowancePolicy $rules, InternRateContext $ctx, AttendancePolicy $company): InternPricing
    {
        $this->reset($rules, $ctx, $company);

        if ($ctx->share !== InternRateContext::SHARE_NONE) {
            $this->setRates();

            foreach ($days as $day) {
                if ($day->worked) {
                    $this->renderedMinutes += $day->workedMinutes;
                }
                if ($day->lateMinutes > 0) {
                    $this->lateMinutes += $day->lateMinutes;
                    // A day HR has already ruled on is not ALSO a late to be counted. chargeDay()
                    // skips this day's late and undertime charges for that very reason ("HR marking
                    // the day a half day is the whole verdict on it"), but the tardiness penalty
                    // charges WHOLE days: uncounted here, a late HR excused can be the third one
                    // that costs an intern a day's allowance. The minutes stay on the record.
                    if (! $day->byHr) {
                        $this->lateCount++;
                    }
                }
                if ($day->undertimeMinutes > 0) {
                    $this->undertimeMinutes += $day->undertimeMinutes;
                }
                foreach ($day->flags as $code) {
                    $this->flag($day->date, $code, null);
                }

                $this->method === 'per_hour' ? $this->earnDay($day) : $this->chargeDay($day);
                $this->payExtraMinutes($day);
            }

            $this->chargeLatePenalty($days);
        }

        return $this->result();
    }

    // --- rates ----------------------------------------------------------------------------------

    private function reset(InternAllowancePolicy $rules, InternRateContext $ctx, AttendancePolicy $company): void
    {
        $this->rules = $rules;
        $this->ctx = $ctx;
        $this->company = $company;
        $this->method = (string) $rules->get('method');
        $this->hoursBased = $rules->isHoursBased();
        $this->prorates = ! ($this->method === 'fixed_monthly' && $rules->get('monthly_proration') === 'none');
        $this->requiredMinutes = max(30, (int) round((float) $rules->get('required_hours_per_day') * 60));

        $this->daily = $this->hourly = $this->holidayPay = $this->restDayPay = 0.0;
        $this->overtimePay = $this->nightPay = $this->hourlyBase = 0.0;
        $this->charges = $this->chargedOn = $this->holidayLines = $this->flags = [];
        $this->restDayMinutes = $this->overtimeMinutes = $this->nightMinutes = $this->workingDays = 0;
        $this->renderedMinutes = $this->paidMinutes = $this->lateMinutes = $this->undertimeMinutes = 0;
        $this->lateCount = 0;
        $this->prevWorkdayUnpaid = null;
    }

    private function setRates(): void
    {
        $amount = $this->ctx->amount;
        $requiredHours = $this->requiredMinutes / 60;

        switch ($this->method) {
            case 'per_hour':
                $this->hourly = $amount;
                $this->daily = $amount * $requiredHours;
                break;
            case 'fixed_monthly':
                // Divided exactly the way a salary is, under the company's daily-rate basis, so an
                // absence costs an intern on a monthly allowance what it would cost on a salary.
                $this->daily = PayrollService::dailyRateFrom(
                    $amount,
                    $this->company,
                    $this->ctx->share === InternRateContext::SHARE_MONTH ? 12 : $this->ctx->periodsPerYear,
                    $this->ctx->scheduledWorkdays,
                );
                $this->hourly = $this->daily / $requiredHours;
                break;
            default: // per_day
                $this->daily = $amount;
                $this->hourly = $amount / $requiredHours;
        }
    }

    // --- allowance by the day or the month: deduction-based ---------------------------------------

    private function chargeDay(InternDay $day): void
    {
        switch ($day->kind) {
            case InternDay::NOT_EMPLOYED:
                // A daily allowance simply is not owed for these days. A monthly one is a figure for
                // the whole period, so the days outside employment come off it, as they do a salary.
                if ($this->method === 'fixed_monthly') {
                    $this->charge($day->date, 'not_employed', 1.0, $day->detail ?? 'Outside employment');
                }

                return;

            case InternDay::PENDING:
                $this->workingDays++;
                $this->prevWorkdayUnpaid = false;

                return;

            case InternDay::DAY_OFF:
                // HR marked this one person's day "not a working day", so it is not theirs to work
                // and not counted. A per_day allowance prices the period as daily × workingDays:
                // counting it would add a full day's allowance for a day nobody was scheduled for.
                // earnDay() has always skipped it — this is the same verdict, not a new one.
                return;

            case InternDay::ABSENT:
                $this->workingDays++;
                $this->chargeAbsence($day);

                return;

            case InternDay::LEAVE:
                $this->workingDays++;
                $unpaid = $this->leaveUnpaid($day);
                if ($unpaid && $this->prorates) {
                    $this->charge($day->date, 'leave_unpaid', 1.0, $this->leaveDetail($day));
                }
                $this->prevWorkdayUnpaid = $unpaid;

                return;

            case InternDay::HALF_DAY:
                $this->workingDays++;
                if ($this->prorates && ! ($day->isLeave && ! $this->leaveUnpaid($day))) {
                    $this->chargeHalf($day, max(1, $day->halves), $day->isLeave ? 'leave_unpaid' : 'half_day', $day->isLeave ? $this->leaveDetail($day) . ' (half day)' : ($day->detail ?? 'Approved half day'));
                }
                // Lateness and undertime are priced on the half that WAS worked, which only an approved
                // half-day request or half-day leave names. HR marking the day a half day is the whole
                // verdict on it — as it is for employees — so nothing further is charged against the
                // afternoon they were excused from.
                if ($day->worked && $day->halves < 2 && ! $day->byHr) {
                    $this->chargeLateAndUndertime($day);
                }
                $this->prevWorkdayUnpaid = false;

                return;

            case InternDay::WORKED:
                if (! $day->isWorkday) {
                    $this->payRestDay($day);

                    return;
                }
                $this->workingDays++;
                $this->chargeWorked($day);
                $this->prevWorkdayUnpaid = false;

                return;

            case InternDay::HOLIDAY:
                $this->priceHoliday($day);

                return;

            case InternDay::SUSPENSION:
                $this->priceSuspension($day);

                return;

            case InternDay::REST_DAY:
                $this->payRestDay($day);

                return;
        }
    }

    private function chargeAbsence(InternDay $day): void
    {
        $rule = $this->rules->get('absence_rule');
        $this->prevWorkdayUnpaid = $rule !== 'no_deduction';

        if (! $this->prorates || $rule === 'no_deduction') {
            return;
        }

        $days = $rule === 'absent_hours'
            ? min(1.0, $day->scheduledMinutes / $this->requiredMinutes)
            : 1.0;

        $this->charge($day->date, 'absent', $days, $day->detail ?? 'Absent');
    }

    private function chargeWorked(InternDay $day): void
    {
        $required = $this->requiredFor($day);
        $fraction = min(1.0, $day->workedMinutes / $required);

        if ($this->belowMinimum($day)) {
            $note = 'Rendered ' . self::hm($day->workedMinutes) . ' of ' . self::hm($required)
                . ', below ' . (int) $this->rules->get('min_attendance_pct') . '%';

            switch ($this->prorates ? $this->rules->get('below_min_attendance') : 'flag_only') {
                case 'no_allowance_for_day':
                    $this->charge($day->date, 'below_min_attendance', 1.0, $note);

                    return;
                case 'treat_as_half_day':
                    $this->chargeHalf($day, 1, 'half_day', $note);

                    return;
                case 'prorate_by_hours':
                    $this->charge($day->date, 'undertime', 1.0 - $fraction, self::shortNote($day, $required) . " — {$note}");

                    return;
                default:
                    $this->flag($day->date, 'below_min_attendance', $note);
            }
        }

        // Paid by the hours rendered: the minutes not worked are the whole charge, and there is no
        // late or undertime rule left to apply on top of them.
        if ($this->hoursBased) {
            if ($this->prorates && $fraction < 1.0) {
                $this->charge($day->date, 'undertime', 1.0 - $fraction, self::shortNote($day, $required));
            }

            return;
        }

        $this->chargeLateAndUndertime($day);
    }

    private function chargeLateAndUndertime(InternDay $day): void
    {
        if ($this->hoursBased) {
            return;
        }

        $required = $this->requiredFor($day);

        if ($day->lateMinutes > 0) {
            $late = "{$day->lateMinutes} min late";

            switch ($this->rules->get('late_rule')) {
                case 'missed_hours':
                    $this->charge($day->date, 'late', $day->lateChargeMinutes / $required, $late);
                    break;
                case 'fixed_amount':
                    $this->charge($day->date, 'late', $this->daysFor((float) $this->rules->get('late_fixed_amount')), "{$late} (fixed charge)");
                    break;
                case 'partial_after_minutes':
                    if ($day->lateMinutes > (int) $this->rules->get('late_partial_after_minutes')) {
                        // A partial day is priced whole: its own rule replaces the undertime below.
                        switch ($this->rules->get('partial_day_pricing')) {
                            case 'by_hours_rendered':
                                $fraction = min(1.0, $day->workedMinutes / $required);
                                $this->charge($day->date, 'undertime', 1.0 - $fraction, self::shortNote($day, $required) . " ({$late}, partial day)");
                                break;
                            case 'no_allowance':
                                $this->charge($day->date, 'late', 1.0, "{$late} — partial day, no allowance");
                                break;
                            default:
                                $this->chargeHalf($day, 1, 'half_day', "{$late} — partial day");
                        }

                        return;
                    }
                    break;
            }
        }

        if ($day->undertimeChargeMinutes > 0) {
            $undertime = "{$day->undertimeMinutes} min undertime";

            switch ($this->rules->get('undertime_rule')) {
                case 'missed_hours':
                    $this->charge($day->date, 'undertime', $day->undertimeChargeMinutes / $required, $undertime);
                    break;
                case 'fixed_amount':
                    $this->charge($day->date, 'undertime', $this->daysFor((float) $this->rules->get('undertime_fixed_amount')), "{$undertime} (fixed charge)");
                    break;
            }
        }

        if ($day->earlyOutMinutes > 0) {
            $earlyOut = "Approved early out, {$day->earlyOutMinutes} min";

            switch ($this->rules->get('early_out_rule')) {
                case 'half_day_rule':
                    $this->chargeHalf($day, 1, 'early_out', $earlyOut);
                    break;
                case 'follow_undertime':
                    match ($this->rules->get('undertime_rule')) {
                        'missed_hours' => $this->charge($day->date, 'early_out', $day->earlyOutMinutes / $required, $earlyOut),
                        'fixed_amount' => $this->charge($day->date, 'early_out', $this->daysFor((float) $this->rules->get('undertime_fixed_amount')), $earlyOut),
                        default => null,
                    };
                    break;
            }
        }
    }

    private function chargeHalf(InternDay $day, int $halves, string $kind, string $detail): void
    {
        $fraction = $day->worked ? min(1.0, $day->workedMinutes / $this->requiredFor($day)) : 0.0;

        $days = match ($this->rules->get('half_day_rule')) {
            'actual_hours' => 1.0 - $fraction,
            'no_allowance' => 1.0,
            'pay_full' => 0.0,
            default => 0.5 * $halves, // pay_50
        };

        $this->charge($day->date, $kind, min(1.0, $days), $detail);
    }

    // --- non-working days ------------------------------------------------------------------------

    private function priceHoliday(InternDay $day): void
    {
        $prefix = $this->holidayPrefix($day);

        // A day the company calendar classifies as an ordinary working day is priced as one.
        if ($prefix === null) {
            $ordinary = $this->asOrdinaryDay($day);
            $this->method === 'per_hour' ? $this->earnDay($ordinary) : $this->chargeDay($ordinary);

            return;
        }

        if (! $day->isWorkday) {
            $this->payHolidayOnRestDay($day, $prefix);

            return;
        }

        $this->workingDays++;

        if (! $day->worked) {
            if ($this->rules->get("{$prefix}_not_worked") === 'no_allowance') {
                $this->method === 'per_hour'
                    ? $this->record($day->date, 'holiday_unpaid', 1.0, $this->holidayLabel($day))
                    : ($this->prorates ? $this->charge($day->date, 'holiday_unpaid', 1.0, $this->holidayLabel($day)) : null);
            } elseif (
                $prefix === 'regular_holiday'
                && $this->rules->get('holiday_forfeit_after_absence')
                && $this->prevWorkdayUnpaid === true
            ) {
                $this->method === 'per_hour'
                    ? $this->record($day->date, 'holiday_forfeited', 1.0, 'Absent without pay the workday before')
                    : ($this->prorates ? $this->charge($day->date, 'holiday_forfeited', 1.0, 'Absent without pay the workday before') : null);
            } elseif ($this->method === 'per_hour') {
                $this->earnMinutes($this->requiredFor($day));
            }

            // A holiday neither breaks nor extends the "absent the day before" chain.
            return;
        }

        $rule = $this->rules->get("{$prefix}_worked");

        if ($rule === 'no_allowance') {
            $this->method === 'per_hour'
                ? $this->record($day->date, 'holiday_unpaid', 1.0, $this->holidayLabel($day) . ' (worked)')
                : ($this->prorates ? $this->charge($day->date, 'holiday_unpaid', 1.0, $this->holidayLabel($day) . ' (worked)') : null);
            $this->flag($day->date, 'holiday_work_unpaid', $this->holidayLabel($day) . ' was worked, and the rules pay no allowance for it');
        } else {
            $this->method === 'per_hour' ? $this->earnMinutes($day->workedMinutes) : $this->chargeWorked($day);

            if ($rule === 'allowance_with_premium') {
                $pct = $this->premiumPct($prefix);
                $this->addHolidayLine($day, $this->dayPay($day, $day->workedMinutes) * ($pct - 100) / 100, $pct, false);
            }
        }

        $this->prevWorkdayUnpaid = false;
    }

    private function payHolidayOnRestDay(InternDay $day, string $prefix): void
    {
        if (! $day->worked) {
            if ($this->rules->get("{$prefix}_rest_day_not_worked") === 'allowance') {
                $this->addHolidayLine($day, $this->dayPay($day, $this->requiredMinutes), 100.0, true);
            }

            return;
        }

        $rule = $this->rules->get("{$prefix}_worked");
        if ($rule === 'no_allowance') {
            $this->flag($day->date, 'holiday_work_unpaid', $this->holidayLabel($day) . ' on a rest day was worked, and the rules pay no allowance for it');

            return;
        }

        $pct = $rule === 'allowance_with_premium' ? $this->premiumPct($prefix) : 100.0;
        $this->addHolidayLine($day, $this->dayPay($day, $day->workedMinutes) * $pct / 100, $pct, true);
    }

    private function priceSuspension(InternDay $day): void
    {
        if (! $day->isWorkday) {
            $this->payRestDay($day);

            return;
        }

        $this->workingDays++;

        // HR suspending one person's day is a decision about that person, and wins. A company-wide
        // suspension is priced by the rule, which may defer to the suspension's own paid flag.
        $rule = $this->rules->get('suspension_not_worked');
        $paid = $day->byHr || $rule === 'follow_suspension'
            ? (bool) $day->suspensionPaid
            : $rule === 'allowance';

        if (! $day->worked) {
            if ($this->method === 'per_hour') {
                $paid
                    ? $this->earnMinutes($this->requiredFor($day))
                    : $this->record($day->date, 'suspended_unpaid', 1.0, $day->detail ?? 'Work suspended');
            } elseif (! $paid && $this->prorates) {
                $this->charge($day->date, 'suspended_unpaid', 1.0, $day->detail ?? 'Work suspended');
            }

            return;
        }

        $this->method === 'per_hour' ? $this->earnMinutes($day->workedMinutes) : $this->chargeWorked($day);

        if ($this->rules->get('suspension_worked') === 'allowance_with_premium') {
            $pct = $this->premiumPct('suspension');
            $this->addHolidayLine($day->with(['holidayType' => 'suspension', 'holidayName' => 'Work suspension']), $this->dayPay($day, $day->workedMinutes) * ($pct - 100) / 100, $pct, false);
        }

        $this->prevWorkdayUnpaid = false;
    }

    private function payRestDay(InternDay $day): void
    {
        if (! $day->worked) {
            return;
        }

        $rule = $this->rules->get('rest_day_worked');
        if ($rule === 'no_allowance') {
            $this->flag($day->date, 'rest_day_work_unpaid', 'A rest day was worked, and the rules pay no allowance for it');

            return;
        }

        $pct = $rule === 'allowance_with_premium' ? $this->premiumPct('rest_day') : 100.0;
        $this->restDayPay += $this->dayPay($day, $day->workedMinutes) * $pct / 100;
        $this->restDayMinutes += $day->workedMinutes;
    }

    // --- allowance by the hour: earned ------------------------------------------------------------

    private function earnDay(InternDay $day): void
    {
        switch ($day->kind) {
            case InternDay::NOT_EMPLOYED:
            case InternDay::DAY_OFF:
                return;

            case InternDay::PENDING:
                $this->prevWorkdayUnpaid = false;

                return;

            case InternDay::ABSENT:
                $this->workingDays++;
                if ($this->rules->get('absence_rule') !== 'no_deduction') {
                    $this->record($day->date, 'absent', 1.0, $day->detail ?? 'Absent');
                }
                $this->prevWorkdayUnpaid = true;

                return;

            case InternDay::LEAVE:
                $this->workingDays++;
                $unpaid = $this->leaveUnpaid($day);
                $unpaid
                    ? $this->record($day->date, 'leave_unpaid', 1.0, $this->leaveDetail($day))
                    : $this->earnMinutes($this->requiredFor($day));
                $this->prevWorkdayUnpaid = $unpaid;

                return;

            case InternDay::HALF_DAY:
                $this->workingDays++;
                if ($day->worked) {
                    $this->earnMinutes($day->workedMinutes);
                }
                if ($day->isLeave && ! $this->leaveUnpaid($day)) {
                    $this->earnMinutes(intdiv($this->requiredFor($day), 2));
                } else {
                    $this->record($day->date, $day->isLeave ? 'leave_unpaid' : 'half_day', min(1.0, 0.5 * max(1, $day->halves)), $day->detail ?? 'Half day');
                }
                $this->prevWorkdayUnpaid = false;

                return;

            case InternDay::WORKED:
                if (! $day->isWorkday) {
                    $this->payRestDay($day);

                    return;
                }
                $this->workingDays++;
                if ($this->belowMinimum($day)) {
                    $note = 'Rendered ' . self::hm($day->workedMinutes) . ' of ' . self::hm($this->requiredFor($day))
                        . ', below ' . (int) $this->rules->get('min_attendance_pct') . '%';
                    if ($this->rules->get('below_min_attendance') === 'no_allowance_for_day') {
                        $this->record($day->date, 'below_min_attendance', 1.0, $note);
                        $this->prevWorkdayUnpaid = false;

                        return;
                    }
                    $this->flag($day->date, 'below_min_attendance', $note);

                    /*
                     * "Treat as a half day" pays half the day the rules call a full one, whatever was
                     * rendered — the same verdict the charge side reaches through `half_day_rule`.
                     * That rule cannot speak for this method (normalise() forces it to `actual_hours`
                     * where pay follows the hours), so half a day is what the setting itself promises.
                     * Recorded at half a day and no pesos: an hourly allowance is earned, not docked.
                     */
                    if ($this->rules->get('below_min_attendance') === 'treat_as_half_day') {
                        $this->record($day->date, 'half_day', 0.5, $note);
                        $this->earnMinutes(intdiv($this->requiredFor($day), 2));
                        $this->prevWorkdayUnpaid = false;

                        return;
                    }
                }
                $this->earnMinutes($day->workedMinutes);
                $this->prevWorkdayUnpaid = false;

                return;

            case InternDay::HOLIDAY:
                $this->priceHoliday($day);

                return;

            case InternDay::SUSPENSION:
                $this->priceSuspension($day);

                return;

            case InternDay::REST_DAY:
                $this->payRestDay($day);

                return;
        }
    }

    private function earnMinutes(int $minutes): void
    {
        $paid = min(max(0, $minutes), $this->maxPaidMinutes());
        $this->hourlyBase += $this->hourly * $paid / 60;
        $this->paidMinutes += $paid;
    }

    // --- premiums on top ---------------------------------------------------------------------------

    private function payExtraMinutes(InternDay $day): void
    {
        $companyType = in_array($day->holidayType, ['regular', 'special_non_working'], true) ? $day->holidayType : null;

        if ($day->overtimeMinutes > 0 && $this->rules->get('overtime_rule') !== 'not_paid') {
            $pct = 100.0;
            if ($this->rules->get('overtime_rule') === 'with_premium') {
                $pct = $this->rules->get('overtime_premium_source') === 'custom'
                    ? (float) $this->rules->get('overtime_premium_pct')
                    : (float) PayrollService::overtimeMultiplierPct($companyType, $day->isWorkday, $this->company);
            }
            $this->overtimePay += $this->hourly * $day->overtimeMinutes / 60 * $pct / 100;
            $this->overtimeMinutes += $day->overtimeMinutes;
        }

        if ($day->nightMinutes > 0 && $this->rules->get('night_diff_rule') !== 'not_paid') {
            $pct = $this->rules->get('night_diff_rule') === 'custom'
                ? (float) $this->rules->get('night_diff_pct')
                : (float) $this->company->night_diff_pct;
            $this->nightPay += $this->hourly * $day->nightMinutes / 60 * $pct / 100;
            $this->nightMinutes += $day->nightMinutes;
        }
    }

    /** @param  list<InternDay>  $days */
    private function chargeLatePenalty(array $days): void
    {
        $perDeduction = (int) $this->company->lates_per_deduction;

        if (
            $this->rules->get('late_penalty_rule') !== 'follow_company'
            || ! $this->company->late_penalty_enabled
            || $perDeduction <= 0
            || $days === []
        ) {
            return;
        }

        $penalty = intdiv($this->lateCount, $perDeduction);
        if ($penalty > 0) {
            $this->charge(
                end($days)->date,
                'late_penalty',
                (float) $penalty,
                "{$this->lateCount} lates × 1 day per {$perDeduction}",
            );
        }
    }

    // --- helpers ---------------------------------------------------------------------------------

    /**
     * Take days off the allowance. A day is never charged more than once over, and a charge on an
     * allowance earned by the hour is a record only — except the late penalty, which is a penalty
     * rather than time not worked.
     */
    private function charge(string $date, string $kind, float $days, ?string $detail): void
    {
        if ($kind !== 'late_penalty') {
            $days = min($days, 1.0 - ($this->chargedOn[$date] ?? 0.0));
        }

        // The LEDGER rounds to four places because a day-figure is read by people. The MONEY must
        // not be taken from it: rounding the days first and multiplying after turns 45 min of an 8h
        // ₱500 day into ₱46.90 where the charge is ₱46.88. Pesos come off the exact fraction, and
        // `days` is only the display of it.
        $exactDays = max(0.0, $days);
        $days = round($exactDays, 4);
        if ($days <= 0) {
            return;
        }

        $this->chargedOn[$date] = ($this->chargedOn[$date] ?? 0.0) + $days;

        $this->charges[] = [
            'date' => $date,
            'kind' => $kind,
            'days' => $days,
            'detail' => $detail,
            'amount' => $this->method === 'per_hour' && $kind !== 'late_penalty'
                ? 0.0
                : round($exactDays * $this->daily, 2),
        ];
    }

    /** A day not paid on an allowance earned by the hour: nothing to take off, but worth the line. */
    private function record(string $date, string $kind, float $days, ?string $detail): void
    {
        $this->charge($date, $kind, $days, $detail);
    }

    private function flag(string $date, string $code, ?string $detail): void
    {
        $this->flags[] = ['date' => $date, 'code' => $code, 'detail' => $detail];
    }

    /**
     * What one day is worth before a premium rate is applied to it.
     *
     * EVERY caller is a premium valuation — a worked rest day, a worked holiday, a worked
     * suspension — never an ordinary day's own allowance, which chargeWorked() and earnMinutes()
     * price. Which of the two readings applies is the company's, not ours: `premium_day_basis`
     * either pays for the hours actually rendered (an intern who clocks one hour of a rest day is
     * owed an hour of premium) or treats any of the day worked as a whole day earned.
     *
     * A day not worked at all reaches here only as the full required minutes, so it values at a
     * whole day under either choice.
     */
    private function dayPay(InternDay $day, int $minutes): float
    {
        if ($this->rules->get('premium_day_basis') === 'whole_day') {
            $minutes = $this->requiredFor($day);
        }

        if ($this->method === 'per_hour') {
            return $this->hourly * min(max(0, $minutes), $this->maxPaidMinutes()) / 60;
        }

        return $this->daily * min(1.0, max(0, $minutes) / $this->requiredFor($day));
    }

    private function addHolidayLine(InternDay $day, float $amount, float $pct, bool $restDay): void
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return;
        }

        $this->holidayPay += $amount;
        $this->holidayLines[] = [
            'date' => $day->date,
            'name' => $day->holidayName,
            'type' => (string) $day->holidayType,
            'rate_pct' => (int) round($pct),
            'rest_day' => $restDay,
            'daily_rate' => round($this->daily, 2),
            'amount' => $amount,
        ];
    }

    /** The whole day's rate, as a percentage of the plain allowance, for a premium day. */
    private function premiumPct(string $prefix): float
    {
        if ($this->rules->get("{$prefix}_premium_source") === 'custom') {
            return (float) $this->rules->get("{$prefix}_premium_pct");
        }

        return (float) match ($prefix) {
            'regular_holiday' => $this->company->premium_regular_holiday_pct,
            'rest_day' => $this->company->premium_rest_day_pct,
            // A suspended day worked has no statutory rate of its own; the special-day rate is the
            // company's nearest stated answer to "a day nobody was due to work".
            default => $this->company->premium_special_day_pct,
        };
    }

    private function holidayPrefix(InternDay $day): ?string
    {
        $type = $day->holidayType;
        if ($type === 'special_working') {
            $type = $this->rules->get('special_working_day') === 'as_special_holiday' ? 'special_non_working' : null;
        }

        return match ($type) {
            'regular' => 'regular_holiday',
            'special_non_working' => 'special_holiday',
            default => null,
        };
    }

    private function asOrdinaryDay(InternDay $day): InternDay
    {
        $kind = match (true) {
            $day->worked => InternDay::WORKED,
            ! $day->isWorkday => InternDay::REST_DAY,
            $day->isPast => InternDay::ABSENT,
            default => InternDay::PENDING,
        };

        return $day->with([
            'kind' => $kind,
            'holidayType' => null,
            'detail' => $kind === InternDay::ABSENT ? 'No time in recorded' : $day->detail,
        ]);
    }

    private function holidayLabel(InternDay $day): string
    {
        $kind = $this->holidayPrefix($day) === 'regular_holiday' ? 'Regular holiday' : 'Special holiday';

        return $day->holidayName ? "{$day->holidayName} ({$kind})" : $kind;
    }

    private function leaveUnpaid(InternDay $day): bool
    {
        return $this->rules->get('leave_pay_rule') === 'unpaid' || $day->leavePaid === false;
    }

    private function leaveDetail(InternDay $day): string
    {
        $unpaid = $this->rules->get('leave_pay_rule') === 'unpaid' ? ' — unpaid for interns' : ' — unpaid leave type';

        return ($day->detail ?? 'Leave') . $unpaid;
    }

    private function belowMinimum(InternDay $day): bool
    {
        $pct = (int) $this->rules->get('min_attendance_pct');

        return $pct > 0 && $day->worked && $day->workedMinutes < $this->requiredFor($day) * $pct / 100;
    }

    /** The minutes a full day asks of this date: the required hours, or a shorter scheduled day. */
    private function requiredFor(InternDay $day): int
    {
        return $day->scheduledMinutes > 0
            ? max(1, min($this->requiredMinutes, $day->scheduledMinutes))
            : $this->requiredMinutes;
    }

    private function maxPaidMinutes(): int
    {
        return $this->method === 'per_hour'
            ? (int) round((float) $this->rules->get('max_paid_hours_per_day') * 60)
            : $this->requiredMinutes;
    }

    /** A peso charge expressed in days of this allowance, so the ledger can carry it. */
    private function daysFor(float $amount): float
    {
        return $this->daily > 0 ? $amount / $this->daily : 0.0;
    }

    /** "95 min short (rendered 6h 25m of 8h 0m)" — leads with the minutes, as the payslip parses them. */
    private static function shortNote(InternDay $day, int $required): string
    {
        $short = max(0, $required - $day->workedMinutes);

        return "{$short} min short (rendered " . self::hm($day->workedMinutes) . ' of ' . self::hm($required) . ')';
    }

    private static function hm(int $minutes): string
    {
        return intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'm';
    }

    private function result(): InternPricing
    {
        $periodBase = match (true) {
            $this->ctx->share === InternRateContext::SHARE_NONE => 0.0,
            $this->method === 'per_day' => $this->daily * $this->workingDays,
            $this->method === 'per_hour' => $this->hourlyBase,
            $this->ctx->share === InternRateContext::SHARE_MONTH => $this->ctx->amount,
            default => $this->ctx->periodsPerYear > 0 ? $this->ctx->amount * 12 / $this->ctx->periodsPerYear : 0.0,
        };

        $peso = static fn (float $v): string => '₱' . number_format($v, 2);
        $note = match ($this->method) {
            'per_day' => $this->workingDays . ' ' . ($this->workingDays === 1 ? 'day' : 'days') . ' × ' . $peso($this->daily),
            'per_hour' => rtrim(rtrim(number_format($this->paidMinutes / 60, 2), '0'), '.') . ' hrs × ' . $peso($this->hourly),
            default => $peso($this->ctx->amount) . ($this->ctx->share === InternRateContext::SHARE_MONTH ? ' for the month' : ' a month'),
        };

        return new InternPricing(
            method: $this->method,
            amount: $this->ctx->amount,
            amountSource: $this->ctx->amountSource,
            dailyRate: round($this->daily, 2),
            hourlyRate: round($this->hourly, 2),
            requiredHoursPerDay: round($this->requiredMinutes / 60, 2),
            periodBase: round($periodBase, 2),
            charges: $this->charges,
            holidayPay: round($this->holidayPay, 2),
            holidayLines: $this->holidayLines,
            restDayPay: round($this->restDayPay, 2),
            restDayMinutes: $this->restDayMinutes,
            overtimePay: round($this->overtimePay, 2),
            overtimeMinutes: $this->overtimeMinutes,
            nightDiffPay: round($this->nightPay, 2),
            nightDiffMinutes: $this->nightMinutes,
            workingDays: $this->workingDays,
            renderedMinutes: $this->renderedMinutes,
            paidMinutes: $this->paidMinutes,
            lateMinutes: $this->lateMinutes,
            undertimeMinutes: $this->undertimeMinutes,
            note: $note,
            flags: $this->flags,
            premiums: [
                'overtime' => $this->rules->get('overtime_rule') !== 'not_paid',
                'night_diff' => $this->rules->get('night_diff_rule') !== 'not_paid',
                'rest_day' => $this->rules->get('rest_day_worked') !== 'no_allowance',
            ],
            settings: $this->rules->toArray(),
        );
    }
}
