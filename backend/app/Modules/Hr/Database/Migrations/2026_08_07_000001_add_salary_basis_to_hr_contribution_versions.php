<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice B19 — a contribution says WHICH salary figure it is charged on, not just how.
 *
 * THE DEFECT. `PayrollService::payslipFor()` passed one number into every contribution: the
 * employee's profile `base_salary`, untouched by absences, lates, overtime, allowances or any
 * premium line. So SSS was charged on money an absent employee never earned, and not charged on
 * overtime they did. Withholding tax has tracked actual earnings since Slice 10 — the base sat
 * three lines above the contribution base and the two disagreed.
 *
 * WHY IT IS PER TYPE AND NOT ONE COMPANY-WIDE RULE. The three agencies do not measure the same
 * thing:
 *
 *   SSS         RA 11199 §8(f) — "compensation": all actual remuneration for employment,
 *               including the mandated COLA. Earnings, not the nominal rate.
 *   PhilHealth  Circular 2019-0009 under the UHC Act — the fixed BASIC monthly salary,
 *               expressly excluding allowances, overtime, COLA and bonuses.
 *   Pag-IBIG    HDMF Circular 460 — monthly compensation. Earnings in principle, though the
 *               ₱10,000 fund-salary ceiling pins almost everybody at the maximum either way.
 *
 * Applying one basis to all three would replace one wrong answer with a different wrong answer.
 *
 * TWO COLUMNS NAMED "BASIS" — the distinction matters and they are not interchangeable:
 *
 *   `basis`         HOW the charge is worked out — `percentage` of the salary, or the `bracket`
 *                   table's Monthly Salary Credit (B3b).
 *   `salary_basis`  WHICH salary figure goes in — the profile's `basic` monthly rate, or the
 *                   `earnings` actually paid for the period (this slice).
 *
 * DEFAULT `basic` IS THE SAFETY, and it is the whole reason this rides on the existing dated
 * versions rather than on a settings row. Every version that exists today keeps charging exactly
 * what it charged, so no completed run reprices and no re-run of an old cycle changes history —
 * the failure `ContributionService::completedRunsAffectedBy()` was built to warn about. HR moves a
 * contribution onto the earnings basis by recording a NEW version with a date, through the same
 * screen and the same backdating warning as a rate change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_contribution_versions', function (Blueprint $table) {
            $table->string('salary_basis', 20)->default('basic')->after('basis');
        });
    }

    public function down(): void
    {
        Schema::table('hr_contribution_versions', function (Blueprint $table) {
            $table->dropColumn('salary_basis');
        });
    }
};
