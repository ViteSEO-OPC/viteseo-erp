<?php

namespace App\Modules\Hr\Support;

use Illuminate\Support\Str;

/** THE EDITABLE LINES OF A PAYSLIP, and what each one does to the money when it moves. */
final class PayslipLines
{
    /**
     * Compensation, taxed. Moving it moves `taxable_pay`, so the year's 13th month, final pay and
     * December true-up all see the correction, and withholding is re-decided marginally on top of
     * what the employee has already been taxed this year.
     */
    public const TAXABLE_EARNING = 'taxable_earning';

    /** Money paid that was never compensation — a reimbursement, an allowance under its ceiling. */
    public const UNTAXED_EARNING = 'untaxed_earning';

    /** A charge that reduced pay: an absence, lateness, undertime, an unpaid suspension. */
    public const ATTENDANCE_CHARGE = 'attendance_charge';

    /**
     * A non-statutory deduction — a benefit share, a loan repayment, a cash advance.
     *
     * Off net only. What was earned was right, so gross, taxable pay and the tax are untouched:
     * refunding tax on a deduction hands back money the BIR is owed.
     */
    public const OTHER_DEDUCTION = 'other_deduction';

    /** SSS, PhilHealth, Pag-IBIG — off net, into their own column, and onto the remittance. */
    public const STATUTORY = 'statutory';

    /** Withholding tax. Off net, and it makes the rest of the year's runs stale — see the service. */
    public const TAX = 'tax';

    /**
     * The fixed lines, in the shape every caller needs them.
     *
     * @var array<string, array{label: string, class: string, column: string, carryover?: string}>
     */
    private const LINES = [
        // — Compensation ————————————————————————————————————————————————————————————
        // The FULL period basic, which is what the document prints. `base_salary` is the same
        // figure net of absences and moves with it; the service writes both.
        'basic' => ['label' => 'Basic Pay', 'class' => self::TAXABLE_EARNING, 'column' => 'period_base'],
        'overtime' => ['label' => 'Overtime', 'class' => self::TAXABLE_EARNING, 'column' => 'overtime_pay'],
        'night_diff' => ['label' => 'Night Differential', 'class' => self::TAXABLE_EARNING, 'column' => 'night_diff_pay'],
        'holiday' => ['label' => 'Holiday', 'class' => self::TAXABLE_EARNING, 'column' => 'holiday_premium'],
        'rest_day' => ['label' => 'Rest Day', 'class' => self::TAXABLE_EARNING, 'column' => 'rest_day_premium'],
        'thirteenth_month' => ['label' => '13th Month Pay', 'class' => self::TAXABLE_EARNING, 'column' => 'thirteenth_month'],
        'leave_conversion' => ['label' => 'Unused Leave Converted', 'class' => self::TAXABLE_EARNING, 'column' => 'leave_conversion'],
        // A reimbursement is the employee's own money coming back. It has never been in any tax
        // base on this payslip and must not enter one by being corrected.
        'reimbursement' => ['label' => 'Reimbursement', 'class' => self::UNTAXED_EARNING, 'column' => 'reimbursements'],
        // Separation pay is exempt or taxable depending on WHY employment ended, and the payslip
        // already records which. Resolved per payslip by classFor(), not fixed here.
        'separation_pay' => ['label' => 'Separation Pay', 'class' => self::UNTAXED_EARNING, 'column' => 'separation_pay'],

        // — Attendance charges ——————————————————————————————————————————————————————
        // All of them write to `absence_deduction`, which is the charge actually applied. The two
        // time-based ones additionally carry a display column of their own, so the row prints the
        // corrected figure rather than the one the minutes implied.
        'absent' => ['label' => 'Absences', 'class' => self::ATTENDANCE_CHARGE, 'column' => 'absence_deduction'],
        'late' => ['label' => 'Late', 'class' => self::ATTENDANCE_CHARGE, 'column' => 'late_deduction'],
        'undertime' => ['label' => 'Undertime', 'class' => self::ATTENDANCE_CHARGE, 'column' => 'undertime_deduction'],
        'half_day' => ['label' => 'Half-Days', 'class' => self::ATTENDANCE_CHARGE, 'column' => 'absence_deduction'],
        'suspended_unpaid' => ['label' => 'Work Suspension', 'class' => self::ATTENDANCE_CHARGE, 'column' => 'absence_deduction'],
        'leave_unpaid' => ['label' => 'Leave Without Pay', 'class' => self::ATTENDANCE_CHARGE, 'column' => 'absence_deduction'],
        'holiday_forfeited' => ['label' => 'Holiday Forfeited', 'class' => self::ATTENDANCE_CHARGE, 'column' => 'absence_deduction'],
        'not_employed' => ['label' => 'Outside Employment', 'class' => self::ATTENDANCE_CHARGE, 'column' => 'absence_deduction'],
        'early_out' => ['label' => 'Early Out', 'class' => self::ATTENDANCE_CHARGE, 'column' => 'absence_deduction'],
        'late_penalty' => ['label' => 'Tardiness Penalty', 'class' => self::ATTENDANCE_CHARGE, 'column' => 'absence_deduction'],

        // — Government ——————————————————————————————————————————————————————————————
        'sss' => ['label' => 'SSS', 'class' => self::STATUTORY, 'column' => 'sss', 'carryover' => 'sss'],
        'philhealth' => ['label' => 'PhilHealth', 'class' => self::STATUTORY, 'column' => 'philhealth', 'carryover' => 'philhealth'],
        'pagibig' => ['label' => 'Pag-IBIG', 'class' => self::STATUTORY, 'column' => 'pagibig', 'carryover' => 'pagibig'],
        'withholding_tax' => ['label' => 'Withholding Tax', 'class' => self::TAX, 'column' => 'withholding_tax', 'carryover' => 'withholding_tax'],
    ];

    /** The two prefixes whose lines are named at RUN TIME rather than here. */
    public const ALLOWANCE_PREFIX = 'allowance:';

    public const OTHER_PREFIX = 'other:';

    /** Every fixed key, for validation and for tests that assert the catalogue is complete. */
    public static function keys(): array
    {
        return array_keys(self::LINES);
    }

    /** Is this a key this system will accept an override for? */
    public static function exists(string $key): bool
    {
        return isset(self::LINES[$key]) || self::isDynamic($key);
    }

    /** A line whose name came from the payslip rather than from this catalogue. */
    public static function isDynamic(string $key): bool
    {
        foreach ([self::ALLOWANCE_PREFIX, self::OTHER_PREFIX] as $prefix) {
            if (str_starts_with($key, $prefix) && strlen($key) > strlen($prefix)) {
                return true;
            }
        }

        return false;
    }

    /** What moves when this line moves. */
    public static function classFor(string $key, bool $separationTaxable = false): ?string
    {
        if ($key === 'separation_pay') {
            return $separationTaxable ? self::TAXABLE_EARNING : self::UNTAXED_EARNING;
        }

        if (str_starts_with($key, self::ALLOWANCE_PREFIX)) {
            // The allowance table on the document is headed "Non-Taxable Allowance": what sat above
            // a benefit's own ceiling was already moved into the taxable base when the payslip was
            // computed, and is not in these lines. Correcting one therefore moves pay and not tax.
            return self::UNTAXED_EARNING;
        }

        if (str_starts_with($key, self::OTHER_PREFIX)) {
            return self::OTHER_DEDUCTION;
        }

        return self::LINES[$key]['class'] ?? null;
    }

    /** The payslip column this line is read from, or null for the dynamic ones (they are lists). */
    public static function columnFor(string $key): ?string
    {
        return self::LINES[$key]['column'] ?? null;
    }

    /** The catalogue's own name for the line, before any label HR has typed over it. */
    public static function labelFor(string $key): ?string
    {
        return self::LINES[$key]['label'] ?? null;
    }

    /** Which obligation a shortfall on this line is recorded under when pay cannot cover it. */
    public static function carryoverKeyFor(string $key): ?string
    {
        if (str_starts_with($key, self::OTHER_PREFIX)) {
            return 'other_deductions';
        }

        return self::LINES[$key]['carryover'] ?? null;
    }

    /** True for the lines printed on the deductions side, which read the other way round. */
    public static function isCharge(string $key, bool $separationTaxable = false): bool
    {
        return in_array(
            self::classFor($key, $separationTaxable),
            [self::ATTENDANCE_CHARGE, self::OTHER_DEDUCTION, self::STATUTORY, self::TAX],
            true,
        );
    }

    /** The key for a line the payslip named itself. */
    public static function keyForName(string $prefix, string $name): string
    {
        return $prefix . Str::slug($name);
    }
}
