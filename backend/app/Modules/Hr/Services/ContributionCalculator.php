<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\ContributionVersion;

/**
 * One statutory contribution, from a resolved rate config and a salary. Pure arithmetic — no
 * database, no dependencies — so both payroll and anything that needs to SHOW what payroll would
 * charge can call the same code.
 *
 * WHY IT LIVES HERE (Slice B7). This was private to `PayrollService`. The employee form needs the
 * calculated figure next to a per-employee override so HR can see what the override is suppressing
 * — and a second implementation of "what SSS costs" is precisely the drift this whole audit is
 * about. One copy, called twice.
 *
 * Deliberately NOT injected anywhere as a dependency: `ContributionService` resolves the config
 * and `PayrollService` owns the payslip, and having either construct the other would be circular.
 * Static because there is no state to hold.
 */
class ContributionCalculator
{
    /**
     * The employee's monthly share.
     *
     * @param  array<string, mixed>  $config  one type's resolved rate version
     */
    public static function employee(array $config, float $base): float
    {
        return self::amount($config, $base, 'rate', 'cap');
    }

    /**
     * The employer's monthly share, including the flat employer-only add-on (SSS's Employees'
     * Compensation fund) where the salary clears its threshold.
     *
     * The add-on is applied AFTER the amount cap and is never mirrored on the employee side: it is
     * the company's cost and must not reach net pay or the withholding base.
     *
     * @param  array<string, mixed>  $config
     */
    public static function employer(array $config, float $base): float
    {
        $amount = self::amount($config, $base, 'employer_rate', 'employer_cap');
        $addOn = self::addOn($config, $base);

        return $addOn > 0 ? round($amount + $addOn, 2) : $amount;
    }

    /**
     * The employer-only add-on for this salary (SSS's Employees' Compensation fund).
     *
     * Slice B8 — the BAND's own amount wins when the published schedule carried one. SSS charges
     * ₱10 up to MSC ₱14,500 and ₱30 from MSC ₱15,000, keyed off the MSC rather than the raw
     * salary; the version-level "₱X above ₱Y" rule could express only one of the two amounts and
     * tested the wrong number (someone on ₱14,900 sits in the ₱15,000 band and owes ₱30, but their
     * salary is below the threshold). The rule remains as the fallback for percentage versions and
     * for any band that does not print an amount.
     *
     * @param  array<string, mixed>  $config
     */
    private static function addOn(array $config, float $base): float
    {
        if (($config['basis'] ?? 'percentage') === 'bracket' && ! empty($config['brackets'])) {
            $band = ContributionVersion::bandFor($config['brackets'], $base);
            if ($band !== null && ($band['employer_add_on'] ?? null) !== null) {
                return (float) $band['employer_add_on'];
            }
        }

        $addOn = (float) ($config['fixed_employer_add_on'] ?? 0);
        if ($addOn <= 0) {
            return 0.0;
        }

        $threshold = $config['add_on_min_salary'] ?? null;

        return ($threshold === null || $base >= (float) $threshold) ? $addOn : 0.0;
    }

    /**
     * Rate × creditable salary, with the floor/cap and amount cap the config carries.
     * `$rateKey`/`$capKey` pick the employee or employer side — the salary floor/cap are shared.
     *
     * @param  array<string, mixed>  $config
     */
    private static function amount(array $config, float $base, string $rateKey, string $capKey): float
    {
        // Slice B3b — a bracket-based contribution (SSS) charges on the band's Monthly Salary
        // Credit, NOT on the salary itself: ₱20,100 is charged on ₱20,000. Percentage-of-salary
        // is wrong for almost everyone, in both directions.
        //
        // A bracket version with no bands falls through to the percentage path rather than
        // charging zero — an unfinished table must not silently wipe a statutory deduction.
        $creditable = null;
        if (($config['basis'] ?? 'percentage') === 'bracket' && ! empty($config['brackets'])) {
            $creditable = ContributionVersion::mscFor($config['brackets'], $base);
        }

        if ($creditable === null) {
            $creditable = $base;
            if (isset($config['salary_floor'])) {
                $creditable = max((float) $config['salary_floor'], $creditable);
            }
            if (isset($config['salary_cap'])) {
                $creditable = min((float) $config['salary_cap'], $creditable);
            }
        }

        $amount = $creditable * (float) ($config[$rateKey] ?? 0);
        if (isset($config[$capKey])) {
            $amount = min((float) $config[$capKey], $amount);
        }

        return round(max(0, $amount), 2);
    }
}
