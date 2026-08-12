<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the year-end tax settlement was arrived at (FIN-054).
 *
 * WHAT THE PAYSLIP SHOWS TODAY. December folds the year's correction into the Withholding Tax
 * line, and on the common case — over-withholding, because one good month early in the year
 * projects a bracket the year never reaches — that line goes NEGATIVE and net pay exceeds gross.
 * The employee sees a negative tax and a net larger than their gross, and nothing anywhere says
 * "this is your year-end refund". `tax_annualisation` records the adjustment but not one figure
 * that explains it.
 *
 * WHY IT MUST BE STORED. The three numbers behind it — the year's taxable pay, the annual tax due
 * on it, and what had already been withheld — are assembled from every OTHER payslip of the year
 * and then discarded. Recomputing them at display time would mean a payslip issued in December
 * 2026 changes its own explanation as later runs, corrections or re-runs alter the year's totals.
 * Same reasoning as `contribution_bases`, `allowances_breakdown` and the run's `policy_snapshot`:
 * what cannot be re-derived is snapshot at the moment it is computed.
 *
 * NULL on every payslip but the one that closes a tax year, and on every payslip written before
 * this column existed — the document shows the settlement block only when it has the figures, and
 * shows nothing rather than a guess when it does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->json('tax_annualisation_basis')->nullable()->after('tax_annualisation');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn('tax_annualisation_basis');
        });
    }
};
