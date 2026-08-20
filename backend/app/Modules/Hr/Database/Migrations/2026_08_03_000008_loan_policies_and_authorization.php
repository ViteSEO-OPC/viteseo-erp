<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A loan becomes a product HR defines, and a deduction the employee has agreed to. */
return new class extends Migration
{
    public function up(): void
    {
        // The product. Lives on the catalogue as `kind = loan_policy` so it inherits the
        // eligibility rule allowances already use — "who may borrow this" is the same question as
        // "who gets this allowance", and a second rule engine would be a second thing to get wrong.
        Schema::table('hr_benefit_programs', function (Blueprint $table) {
            $table->decimal('loan_max_principal', 12, 2)->nullable()->after('ceiling_period');
            $table->unsignedSmallInteger('loan_term_periods')->nullable()->after('loan_max_principal');
            // Percent per annum. Null and 0 both mean interest-free, which is the common company
            // loan; the column exists so a policy that charges cannot be expressed only in a note.
            $table->decimal('loan_interest_rate', 6, 3)->nullable()->after('loan_term_periods');
        });

        Schema::table('hr_employee_loans', function (Blueprint $table) {
            // Which policy this loan was issued under. Nullable: the three loans that predate this
            // slice (there are none today) and any ad-hoc arrangement have no product behind them.
            $table->foreignId('benefit_program_id')->nullable()->after('employee_id')
                ->constrained('hr_benefit_programs')->nullOnDelete();

            // The two origination paths, and who decided.
            $table->timestamp('requested_at')->nullable()->after('start_date');
            $table->foreignId('approved_by')->nullable()->after('requested_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('decision_note')->nullable()->after('approved_at');

            // The consent that makes the deduction lawful. Set by the EMPLOYEE, never by HR.
            $table->timestamp('authorized_at')->nullable()->after('decision_note');
            $table->foreignId('authorized_by')->nullable()->after('authorized_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_employee_loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('benefit_program_id');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('authorized_by');
            $table->dropColumn(['requested_at', 'approved_at', 'decision_note', 'authorized_at']);
        });

        Schema::table('hr_benefit_programs', function (Blueprint $table) {
            $table->dropColumn(['loan_max_principal', 'loan_term_periods', 'loan_interest_rate']);
        });
    }
};
