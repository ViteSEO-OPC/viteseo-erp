<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice B6, final answer — there are no per-employee allowance amounts. An enrolment means the
 * employee is paid what the programme says, full stop.
 *
 * WHY THIS DROPS RATHER THAN CLEARS. B1 froze every employee's old figure onto their enrolment so
 * the switch to Company → Benefits paid identically on day one, and B6 built the screen to work
 * through those 56 pins. The decision (2026-07-29) is that the company does not want per-employee
 * amounts at all — so the pins go, and so does the mechanism. A column payroll no longer reads is
 * exactly the "field that does nothing" this audit keeps finding; leaving it behind would invite
 * the next person to fill it in.
 *
 * THIS CHANGES PAY. All 14 employees move to their programme amounts: +₱35,000/month across the
 * company — 9 gain Gas Allowance (₱1,500, never paid since it had no payroll column), 3 who were
 * pinned at ₱0 gain a full ₱5,500, and Maya Villanueva nets −₱500 (she gains Gas ₱1,500 and loses
 * the ₱2,000 she was pinned above Meal/Transport).
 *
 * ROLLBACK. `down()` restores the columns EMPTY — the pinned values are not recoverable from here.
 * They are still readable in `hr_employees.food_allowance` / `transportation_allowance` /
 * `load_allowance`, which B1 stopped reading but has not yet dropped (that is B9's job). If those
 * are dropped first, this record is gone for good.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_benefit_enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('amount_set_by');
            $table->dropColumn(['amount', 'amount_set_at']);
        });
    }

    public function down(): void
    {
        Schema::table('hr_benefit_enrollments', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->nullable()->after('employee_id');
            $table->foreignId('amount_set_by')->nullable()->after('amount')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('amount_set_at')->nullable()->after('amount_set_by');
        });
    }
};
