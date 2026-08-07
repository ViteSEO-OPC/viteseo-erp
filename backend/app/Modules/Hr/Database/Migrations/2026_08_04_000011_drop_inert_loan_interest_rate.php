<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes `loan_interest_rate`, which was configurable and never charged (FIN-031).
 *
 * WHAT IT DID. Nothing. The column was added by Slice B15, validated on store and update, editable
 * in the Benefits UI behind a field whose placeholder read "0 — interest-free" — which tells HR that
 * a non-zero value charges interest. `LoanService` never referenced it. A module-wide grep for
 * `interest` returned the migration, the model's `$fillable`, two form requests and the resource,
 * and nothing anywhere in the collection path.
 *
 * WHY REMOVING BEATS IMPLEMENTING, for now. The audit's position is that a configurable rate which
 * does nothing is worse than no field: HR can publish a 6%-per-annum product, the employee can be
 * shown that rate, and payroll collects principal only — the configuration lying about what will be
 * charged, in the employee's favour, without anyone's knowledge, and the company under-recovering.
 *
 * Charging interest properly is a larger piece that needs decisions nobody has made: flat or
 * diminishing balance, accrued per period or added up front, its own payslip line or folded into the
 * instalment, and how it interacts with the gross deduction cap (FIN-053) and arrears recovery
 * (FIN-005). Multiplying a percentage inside `collectForPeriod()` without those answers would
 * replace one honest gap with a second wrong number.
 *
 * NOTHING IS LOST. No `loan_policy` programme exists with a rate set — checked before writing this.
 * `down()` restores the column, so the schema change is reversible even though the intent is not to
 * reverse it: when interest is genuinely needed it should arrive with an amortisation schedule
 * beside it, not as a lone percentage.
 *
 * SSS and Pag-IBIG salary loans DO charge interest, and the audit notes those cannot be represented
 * at all today. That remains true and remains open — this migration does not close it, it stops
 * pretending it is closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_benefit_programs', function (Blueprint $table) {
            $table->dropColumn('loan_interest_rate');
        });
    }

    public function down(): void
    {
        Schema::table('hr_benefit_programs', function (Blueprint $table) {
            // Restored exactly as Slice B15 defined it, so a rollback is a true inverse.
            $table->decimal('loan_interest_rate', 6, 3)->nullable()->after('loan_term_periods');
        });
    }
};
