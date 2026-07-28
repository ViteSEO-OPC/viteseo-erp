<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 9 (refinement) — separate tolerances for lunch and for breaks.
 *
 * The first cut pooled them: `lunch_minutes + break_minutes` against the combined entitlement
 * with one grace. That let an employee trade one for the other — a 20-minute lunch plus a
 * 60-minute break totals the same 80 as 60 + 20, so a break running 3× its allowance never
 * showed up. HR asked for the two to be judged on their own.
 *
 * Each grace may be 0, which means no tolerance at all: the comparison is strictly greater
 * than, so exactly the allowance still passes and one minute over is flagged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->unsignedSmallInteger('lunch_grace_minutes')->default(15)->after('hours_per_day_divisor');
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->dropColumn('lunch_grace_minutes');
        });
    }
};
