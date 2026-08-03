<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice B17 — 13th month pay becomes something payroll PAYS, not something a report mentions.
 *
 * WHY. It was computed as Σ basic ÷ 12 and shown on a report. No payslip ever carried it, so no
 * tax was ever withheld on it — and it is the largest item in the ₱90,000 exemption it shares with
 * the excess de minimis that B16 now routes there. Anyone whose 13th month plus bonuses clears
 * ₱90,000 was under-withheld, and the company would have discovered it at year-end.
 *
 * WITHHELD WHEN PAID, not projected across the year (decided 2026-08-03). Projecting would raise
 * withholding on every ordinary payslip for anyone earning over roughly ₱90,000 — and withholding
 * on money no payslip has disbursed is its own kind of wrong.
 *
 * THE THREE NEW PAYSLIP COLUMNS EXIST SO THE YEAR CAN BE READ BACK FROM WHAT WAS ACTUALLY PAID,
 * rather than recomputed from today's configuration. Recomputing would let a mid-year allowance
 * change rewrite the bucket a December run is measured against:
 *
 *   thirteenth_month  what this slip paid as 13th month (zero on an ordinary slip)
 *   other_benefits    this period's contribution to the ₱90,000 bucket — the amount paid above a
 *                     benefit's own de-minimis ceiling (B16)
 *   taxable_pay       this period's taxable compensation, net of the employee's statutory
 *                     contributions. Summed over the year it is the actual annual taxable
 *                     compensation, which is what the marginal tax on the bucket is computed
 *                     against. An annualised PROJECTION could not be summed — twelve projections
 *                     of a year are not a year.
 *
 * `type` on the run keeps a 13th-month run out of the regular sequence. The unique index is
 * (cycle_start, cycle_end, frequency), so a 13th-month run uses `frequency = 'thirteenth_month'`
 * and cannot collide with a December monthly run over the same dates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $table->string('type', 20)->default('regular')->after('frequency');
        });

        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->decimal('thirteenth_month', 12, 2)->default(0)->after('rest_day_minutes');
            $table->decimal('other_benefits', 12, 2)->default(0)->after('thirteenth_month');
            $table->decimal('taxable_pay', 12, 2)->default(0)->after('other_benefits');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn(['thirteenth_month', 'other_benefits', 'taxable_pay']);
        });

        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
