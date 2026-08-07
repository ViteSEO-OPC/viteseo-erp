<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uncollected deductions are recovered from later periods (FIN-005).
 *
 * WHERE THIS PICKS UP. FIN-053 made a thin payslip honest: when gross cannot cover everything owed,
 * the cap is allocated across the obligations and `deduction_carryover_breakdown` records which one
 * went short and by how much. That closed the reporting half — the remittance report no longer
 * claims more was withheld than was taken.
 *
 * It did not close the money half. Nothing read that column back. The shortfall was named and then
 * forgotten: the employee's statutory record stayed short, and the company still owed the agency the
 * full contribution, so an uncollected ₱800 of SSS became an unrecorded employer liability. The
 * audit's phrase was that the amount was "displayed as uncollected and never recovered".
 *
 * NO BALANCE COLUMN — the balance is DERIVED, exactly as `EmployeeLoan` derives from
 * `hr_loan_payments`:
 *
 *     outstanding(obligation) = Σ deduction_carryover_breakdown − Σ arrears_recovered_breakdown
 *
 * That is what makes it re-run safe for free. A payslip recompute replaces its own row, so both
 * sums move together; a stored running balance decremented per run would double-count on every
 * recompute, which is the failure Slices 5, 12 and 11b each had to fix in turn.
 *
 * WHY THE RECOVERED AMOUNTS ALSO GO INTO `sss` / `philhealth` / … The remittance report sums those
 * columns, and money recovered this period genuinely IS remitted this period — that is how arrears
 * work. The breakdown column keeps the distinction available for anyone who needs to know which
 * period the obligation arose in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            // Total arrears recovered on this payslip, on top of its own period's obligations.
            $table->decimal('arrears_recovered', 12, 2)->default(0)->after('deduction_carryover_breakdown');
            // obligation => amount recovered. Null when nothing was recovered, which is almost
            // every payslip — the same shape as the carryover breakdown it settles.
            $table->json('arrears_recovered_breakdown')->nullable()->after('arrears_recovered');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn(['arrears_recovered', 'arrears_recovered_breakdown']);
        });
    }
};
