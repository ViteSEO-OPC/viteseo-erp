<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 9 (2026-07-28 audit) — make the break rule real, and stop duplicating the schedule.
 *
 * `max_break_minutes_per_day` was a flat company ceiling (120) sitting alongside the per-schedule
 * entitlement the work schedule ALREADY defines (60 unpaid + 20 paid = 80). Two problems: the
 * company number can't respect a per-schedule or per-employee break, and at 120 vs 80 an employee
 * could take 50% more break than they were entitled to and never trip it.
 *
 * The threshold now comes from the employee's own effective schedule. What stays configurable is
 * the TOLERANCE on top of it, so a couple of minutes over doesn't raise a flag.
 *
 * `over_max_break_action` goes with it: a single-valued enum ('flag_only') that was validated to
 * that one value and read by nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->unsignedSmallInteger('break_grace_minutes')->default(15)->after('hours_per_day_divisor');
            $table->dropColumn(['max_break_minutes_per_day', 'over_max_break_action']);
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_break_minutes_per_day')->default(120)->after('hours_per_day_divisor');
            $table->string('over_max_break_action')->default('flag_only')->after('max_break_minutes_per_day');
            $table->dropColumn('break_grace_minutes');
        });
    }
};
