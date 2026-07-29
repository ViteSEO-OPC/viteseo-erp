<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice B6 — tell a DELIBERATE per-employee allowance override apart from one the B1 backfill
 * froze on.
 *
 * WHY. B1 wrote every employee's old `hr_employees` column value onto their enrolment so the
 * switch to Company → Benefits paid identically on day one. That left 56 pinned rows which are
 * indistinguishable, in the data, from an amount HR typed on purpose — and the whole point of B6
 * is to work through the frozen ones without disturbing a real arrangement. A row that carries
 * WHO set it and WHEN was set by a person; a row with a non-null `amount` and neither is a
 * leftover from the migration.
 *
 * Deliberately NOT a boolean flag: "set by Joshua on 12 Aug" answers the question HR will
 * actually ask when they meet an override, and a flag does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_benefit_enrollments', function (Blueprint $table) {
            $table->foreignId('amount_set_by')->nullable()->after('amount')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('amount_set_at')->nullable()->after('amount_set_by');
        });

        // Every existing override predates this column, so it is a backfill pin by definition —
        // which is exactly what leaving both columns NULL says. No data change needed.
    }

    public function down(): void
    {
        Schema::table('hr_benefit_enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('amount_set_by');
            $table->dropColumn('amount_set_at');
        });
    }
};
