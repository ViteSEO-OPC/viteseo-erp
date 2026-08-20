<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Loan interest, fees and collection priority — the last of. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employee_loans', function (Blueprint $table) {
            // A percentage, e.g. 10.5000 for 10.5%. 0 = interest-free.
            $table->decimal('interest_rate', 7, 4)->default(0)->after('per_period_amount');
            // How that rate is quoted: 'annual' | 'monthly'. See the class docblock.
            $table->string('interest_period')->default('annual')->after('interest_rate');
            // A one-off charge (processing / service fee), collected with the first instalment.
            $table->decimal('fees', 12, 2)->default(0)->after('interest_period');
            /** Collection order when net pay cannot cover everything. LOWER RUNS FIRST. */
            $table->unsignedSmallInteger('priority')->default(100)->after('fees');
        });

        Schema::table('hr_loan_payments', function (Blueprint $table) {
            // `amount` stays the total collected. These three say what it was made of, so the
            // outstanding balance can derive from principal alone.
            $table->decimal('principal_amount', 12, 2)->default(0)->after('amount');
            $table->decimal('interest_amount', 12, 2)->default(0)->after('principal_amount');
            $table->decimal('fee_amount', 12, 2)->default(0)->after('interest_amount');
        });

        // Every payment made before this migration was pure principal, because nothing charged
        // anything else. Backfilling is what keeps `outstanding()` correct across the change.
        DB::table('hr_loan_payments')->update(['principal_amount' => DB::raw('amount')]);

        Schema::table('hr_payslips', function (Blueprint $table) {
            // Its own column, not just a line inside `other_deductions_breakdown`: the payslip has to
            // show the employee what they paid FOR the money as distinct from what they paid BACK, and
            // a total that has to be recovered by string-matching breakdown labels is not a total.
            $table->decimal('loan_interest', 12, 2)->default(0)->after('other_deductions');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employee_loans', function (Blueprint $table) {
            $table->dropColumn(['interest_rate', 'interest_period', 'fees', 'priority']);
        });

        Schema::table('hr_loan_payments', function (Blueprint $table) {
            $table->dropColumn(['principal_amount', 'interest_amount', 'fee_amount']);
        });

        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn('loan_interest');
        });
    }
};
