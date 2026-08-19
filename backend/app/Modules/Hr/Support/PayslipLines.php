<?php

namespace App\Modules\Hr\Support;

use Illuminate\Support\Str;

/**
 * THE EDITABLE LINES OF A PAYSLIP, and what each one does to the money when it moves.
 *
 * WHY THIS EXISTS. HR can now correct a payslip by editing the line that is wrong — "Late ₱500"
 * becomes "₱300" on the document itself — instead of appending a correction underneath it. That
 * turns every printed line into something three separate places have to agree about: the resource
 * that serialises it, the service that applies the override, and the request that validates the key
 * coming back. Three hand-maintained lists would disagree the first time a run type gained a line,
 * which is exactly how the adjustment and final-pay earnings shipped invisible on both documents.
 *
 * WHAT A CLASS IS FOR. The old correction dialog asked HR to choose "extra pay / pay reversed /
 * deduction", which is a question about TAX wearing the clothes of a question about direction — and
 * one HR was being asked to answer for a line that already knows. Overtime is taxable compensation;
 * a missed HMO share is not compensation at all. So the class travels with the line, and cutting an
 * overtime line refunds the tax withheld on it without anyone being asked.
 *
 * WHAT IS DELIBERATELY ABSENT: gross, total deductions, and net pay. They are sums of the lines
 * above them, and a payslip whose parts do not foot to its total is the one error a payslip cannot
 * make. Every figure here is a LEAF — edit it and the totals move by themselves.
 */
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

    /**
     * A charge that reduced pay: an absence, lateness, undertime, an unpaid suspension.
     *
     * The mirror image of a taxable earning — raising the charge lowers the pay it came off, and
     * lowers the tax with it. It is its own class rather than "negative earnings" because it is
     * PRINTED on the deductions side while it is SUBTRACTED from the compensation side, and the
     * document's identity only closes if both halves move together (see PayslipResource::
     * documentTotals — `compensation` leads with the full period basic precisely so the charge can
     * reappear below it and cancel).
     */
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
     *   column     — the payslip column the figure is READ from and the delta written to.
     *   class      — what moves when it moves, above.
     *   carryover  — the obligation key a shortfall is recorded under when the payslip cannot
     *                cover an increased deduction (FIN-005/FIN-053). Null for anything that
     *                cannot go short, which is everything on the earnings side.
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

    /**
     * The two prefixes whose lines are named at RUN TIME rather than here.
     *
     * An allowance is whatever the employee's package calls it ("Transport Allowance"); an
     * `other:` line is a benefit share, a loan repayment or a correction already on the payslip.
     * Neither can be enumerated in advance, so the key carries a slug of the name and the payslip's
     * own breakdown is what it is matched against — see keyForName().
     */
    public const ALLOWANCE_PREFIX = 'allowance:';

    public const OTHER_PREFIX = 'other:';

    /** Every fixed key, for validation and for tests that assert the catalogue is complete. */
    public static function keys(): array
    {
        return array_keys(self::LINES);
    }

    /**
     * Is this a key this system will accept an override for?
     *
     * Deliberately permissive about the two dynamic prefixes: whether a payslip actually HAS an
     * allowance called "transport-allowance" is a question about that payslip, answered where the
     * override is applied. This answers only whether the key is well formed.
     */
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

    /**
     * What moves when this line moves.
     *
     * `$separationTaxable` is the one line whose class is a property of the PAYSLIP rather than of
     * the line: separation pay is tax-exempt when employment ended for a cause the law exempts and
     * compensation when it did not, and the payslip already stores which. Passing it in keeps the
     * distinction where it is recorded instead of duplicating the rule here.
     */
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

    /**
     * Which obligation a shortfall on this line is recorded under when pay cannot cover it.
     *
     * The same five keys `deductionArrearsFor()` already recovers against, so an increased
     * deduction the payslip could not take is chased by the machinery that exists rather than
     * being written off — see applyAmendmentDeductionTo() for the same rule stated the other way.
     */
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

    /**
     * The key for a line the payslip named itself.
     *
     * Slugged from the name because that is the only stable handle a benefit share or a loan
     * repayment has — neither carries an id onto the payslip, and the breakdown is a plain list of
     * `{name, amount}`. Renaming the benefit therefore orphans the override, which is the honest
     * outcome: the line HR corrected is not the line now printed.
     */
    public static function keyForName(string $prefix, string $name): string
    {
        return $prefix . Str::slug($name);
    }
}
