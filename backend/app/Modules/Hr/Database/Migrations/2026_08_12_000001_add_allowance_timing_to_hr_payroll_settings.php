<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHICH CUTOFF AN ALLOWANCE LANDS ON (FIN-048).
 *
 * Allowances are configured as MONTHLY figures but have only ever been paid as
 * `monthly × 12 ÷ periodsPerYear` — an even split across every cutoff. On a semi-monthly group a
 * ₱1,500 gas allowance therefore shows as ₱750 twice, which reads as a wrong number rather than
 * as half of one: nothing on the payslip or in Company → Rules said a split was happening.
 *
 * `deduction_timing` and `tax_timing` already answer exactly this question for the other side of
 * the payslip, with the same three values and the same meaning, so this is deliberately the third
 * member of that set rather than a new mechanism.
 *
 *   every  split evenly across the month's cutoffs — TODAY'S BEHAVIOUR, hence the default
 *   first  the whole monthly amount on the month's first cutoff
 *   last   the whole monthly amount on the month's last cutoff
 *
 * Defaulting to `every` means this migration changes no one's pay: an existing row reads `every`
 * and prices exactly as it did before.
 *
 * NO SEPARATE VERSION TABLE. `hr_payroll_setting_versions` stores the whole settings row as a
 * `payload`, so a new column is versioned the day it is added with nothing further to do — which
 * is precisely why that migration chose a payload over a hand-picked field list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_settings', function (Blueprint $table) {
            $table->string('allowance_timing', 20)->default('every')->after('tax_timing');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payroll_settings', function (Blueprint $table) {
            $table->dropColumn('allowance_timing');
        });
    }
};
