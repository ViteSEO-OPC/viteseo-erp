<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which obligation went uncollected when pay could not cover the deductions (FIN-053).
 *
 * THE DEFECT THIS CLOSES. `total_deductions` has been capped at gross since the carryover column
 * was added, but the five component columns it is made of — `sss`, `philhealth`, `pagibig`,
 * `withholding_tax`, `other_deductions` — kept their full COMPUTED values. On any payslip where
 * gross could not cover the deductions the components therefore summed to MORE than the total
 * actually taken, and the payslip did not reconcile against itself.
 *
 * That was survivable while the columns were only read one payslip at a time, because
 * `PayslipResource::documentTotals()` credited the aggregate shortfall back as an `uncollected`
 * term and the document resolved. It stops being survivable the moment anything AGGREGATES those
 * columns: the government remittance report sums them directly, so it reported more withheld from
 * SSS, PhilHealth, Pag-IBIG and BIR than was ever taken off anyone's pay. A filing built on that
 * over-reports employee contributions — the company would be remitting figures it cannot support
 * from its own payslips.
 *
 * WHAT CHANGES. The five columns now store what was COLLECTED. The cap is allocated across them in
 * a fixed priority order (statutory → tax → benefit shares) and the remainder is recorded here, per
 * obligation, so `sss + philhealth + pagibig + withholding_tax + other_deductions ==
 * total_deductions` holds on every payslip.
 *
 * WHY A BREAKDOWN COLUMN RATHER THAN A PARALLEL SET OF "COMPUTED" COLUMNS. Five more decimal
 * columns would carry the same information at five times the schema cost, and the computed figure
 * is always recoverable as collected + uncollected. It also matches how the payslip already stores
 * the shape of a deduction it needs to explain rather than just total (`other_deductions_breakdown`).
 *
 * `deduction_carryover` is unchanged and still holds the aggregate — it is the sum of this column's
 * values. Keeping it means every existing reader (the payslip document, the resource, HR's arrears
 * figure) is untouched by this migration.
 *
 * NOT BACKFILLED, deliberately. A payslip written before this migration stored uncapped components,
 * and there is no way to reconstruct the allocation after the fact without re-running the period —
 * which would reprice it under today's rules (see the policy-snapshot note in PayrollService). Old
 * payslips keep the figures they were issued with; a null here means "issued before the split", not
 * "nothing was uncollected". Rows with `deduction_carryover = 0` were never affected either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            // {sss, philhealth, pagibig, withholding_tax, other_deductions} => uncollected amount.
            // Null on payslips issued before the components were capped, and on any payslip where
            // gross covered the deductions in full — there is nothing to itemise.
            $table->json('deduction_carryover_breakdown')->nullable()->after('deduction_carryover');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn('deduction_carryover_breakdown');
        });
    }
};
