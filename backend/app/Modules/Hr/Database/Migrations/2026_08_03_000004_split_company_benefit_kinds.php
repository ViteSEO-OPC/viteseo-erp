<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slice B2 — `kind` says what the money DOES, so it has to be precise enough to say it.
 *
 * WHY. The catalogue had two kinds: `allowance` (adds to gross) and `company` (everything else).
 * "Everything else" is not one behaviour — an HMO deducts a fixed share every period, while a loan
 * deducts against a balance that decrements to zero. They share a section on the page and nothing
 * else: different config, different enrolment, different payroll path. One loose kind meant one
 * loose validation shape covering both, which is how a field ends up unvalidated.
 *
 *   company → plan          deducts a fixed amount each period (HMO, insurance, retirement)
 *             loan_policy   a product a loan is issued under (B15). NOT a loan: the debt itself
 *                           lives in `hr_employee_loans`, because a balance that decrements to
 *                           zero cannot be a programme without one programme per borrower.
 *
 * Nothing moves to `loan_policy` here — no loan policies exist yet. The value is reserved so B15
 * adds screens rather than another migration.
 *
 * ALSO: `enrolled_at` / `ended_at` on the enrolment, promoted from Known gaps. An enrolment
 * recorded no dates at all, so a plan joined mid-month was charged a full period's share and a
 * leaver's last deduction could not be dated. They must exist BEFORE anyone is enrolled: adding
 * them afterwards means guessing when everybody joined. `enrolled_at` is backfilled from the
 * row's own `created_at`, which is the closest thing to the truth that exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('hr_benefit_programs')->where('kind', 'company')->update(['kind' => 'plan']);

        Schema::table('hr_benefit_enrollments', function (Blueprint $table) {
            $table->date('enrolled_at')->nullable()->after('employee_share');
            $table->date('ended_at')->nullable()->after('enrolled_at');
        });

        // The row's own creation date is the best available answer for existing enrolments, and a
        // better one than null — null would read as "enrolled forever", including before the
        // company had the plan.
        DB::statement('UPDATE hr_benefit_enrollments SET enrolled_at = DATE(created_at) WHERE enrolled_at IS NULL');
    }

    public function down(): void
    {
        Schema::table('hr_benefit_enrollments', function (Blueprint $table) {
            $table->dropColumn(['enrolled_at', 'ended_at']);
        });

        DB::table('hr_benefit_programs')->where('kind', 'plan')->update(['kind' => 'company']);
    }
};
