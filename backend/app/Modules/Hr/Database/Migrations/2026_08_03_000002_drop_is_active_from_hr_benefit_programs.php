<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Slice B13 item 4 — one off switch for a benefit programme, not three.
 *
 * WHAT WAS WRONG. A programme could be switched off three ways: `is_active = false`, a soft
 * delete, or a `status` string that says something like "Coming soon". Only ONE of them was
 * reachable from the product — the Delete button — so an `is_active = false` programme dropped off
 * Company → Benefits with nothing able to show it again, and `status` drifted into a fourth
 * meaning of its own (new allowances were saved as "Enrolled", seeded ones as "Active", both of
 * them live).
 *
 * WHY `is_active` IS THE ONE TO GO. The soft delete already does everything it did and does it
 * reversibly: `deleted_at` hides the programme from every query, keeps the row for the payslips
 * that reference it, and can be undone. `is_active` was a second, irreversible-by-UI copy of the
 * same idea. `status` stays, but only where it does real work — draft versus published for a
 * company plan — and the employee-facing badge for an allowance is now derived rather than typed.
 *
 * NOTHING IS SWITCHED ON BY THIS. Any programme sitting at `is_active = false` is soft-deleted
 * first, so dropping the column cannot quietly start paying an allowance somebody had disabled.
 * (There are none on the live database today; this is here for every other copy of it.)
 *
 * ROLLBACK restores the column defaulting to true. The programmes this migration soft-deleted stay
 * deleted — which is the same "off" they were in before, expressed the surviving way.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('hr_benefit_programs')
            ->where('is_active', false)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        Schema::table('hr_benefit_programs', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('hr_benefit_programs', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('footer_label');
        });
    }
};
