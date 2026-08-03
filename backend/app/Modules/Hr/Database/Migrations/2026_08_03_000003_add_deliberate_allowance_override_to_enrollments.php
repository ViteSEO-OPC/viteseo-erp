<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice B14 — one employee, a different amount.
 *
 * THIS RE-ADDS WHAT B6 DROPPED, AND THAT IS DELIBERATE. Joshua's instruction, 2026-08-03: HR must
 * be able to pay a specific employee a different allowance from the rest of the company. B6
 * removed per-employee amounts outright on the earlier call that the company did not want them.
 *
 * IT IS NOT AN UNDO. B6 existed because EVERY enrolment carried an amount written by the B1
 * backfill migration, so an edit on the Benefits page reached nobody and the page drove no one's
 * pay. The column comes back with the distinction that was missing then:
 *
 *   amount IS NULL      → follows the programme. An edit to the allowance reaches this person.
 *                         The default, and it must stay the overwhelming majority.
 *   amount IS NOT NULL  → somebody typed it, and `amount_set_by` / `amount_set_at` say who and
 *                         when. An edit to the allowance leaves this person alone, which is the
 *                         entire point of having set it.
 *
 * NOTHING BUT A PERSON WRITES THIS COLUMN. There is no backfill here and there must never be one:
 * a migration that fills it for everybody recreates the exact bug B1 shipped and B6 removed.
 *
 * A ROW HERE IS NOT A MEMBERSHIP. Since 2026-07-29 an allowance's membership is its eligibility
 * rule, and rule-based allowances have no enrolment rows at all. So the row this column hangs on
 * is created BY setting an override, and means "this person is paid differently" — not "this
 * person is on the allowance". The service refuses an override for someone the rule does not
 * cover, or the pivot quietly becomes a second, invisible membership list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_benefit_enrollments', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->nullable()->after('employee_id');
            // Provenance is not decoration: "who set this and when" is the only thing that tells a
            // deliberate arrangement apart from a leftover, and having no answer is what made B6's
            // 56 frozen rows impossible to work through one at a time.
            $table->foreignId('amount_set_by')->nullable()->after('amount')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('amount_set_at')->nullable()->after('amount_set_by');
        });
    }

    public function down(): void
    {
        Schema::table('hr_benefit_enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('amount_set_by');
            $table->dropColumn(['amount', 'amount_set_at']);
        });
    }
};
