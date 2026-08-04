<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The year-end tax true-up, recorded on the payslip that carries it (FIN-028).
 *
 * WHY A YEAR-END ADJUSTMENT IS NEEDED AT ALL. Every period withholds by ANNUALISING that period:
 * this cutoff's taxable pay × the number of periods in a year, taxed, then divided back down. That
 * is the right way to withhold when you cannot see the future — but it is a projection, and it is
 * only correct if every period is identical. They are not: overtime in March, none in April, a
 * raise in August, unpaid days in June. Twelve projections of a year are not a year, so the sum of
 * what was withheld drifts from the tax actually due on what was actually earned.
 *
 * Nobody notices during the year. It surfaces when the employee files, or when the company issues
 * BIR 2316 and the figures do not agree with the withholding it remitted.
 *
 * WHAT THE FINAL RUN DOES. On the cycle containing 31 December it stops projecting and settles:
 * annual tax due on the year's actual taxable pay, minus everything already withheld this year.
 * The result can be negative — the employee over-withheld and is refunded on that payslip, which
 * is why nothing here clamps it at zero.
 *
 * THIS COLUMN IS THE DIFFERENCE, not the tax. `withholding_tax` remains what was withheld this
 * period; this records how much of it is the correction, so the payslip can show a line saying
 * "year-end adjustment: −₱2,340" instead of presenting an unexplained figure on the December
 * payslip. A legally consequential document has to be able to explain itself (the same argument
 * as FIN-011).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            // Signed on purpose: positive = extra tax collected at year end, negative = refunded.
            $table->decimal('tax_annualisation', 12, 2)->default(0)->after('withholding_tax');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn('tax_annualisation');
        });
    }
};
