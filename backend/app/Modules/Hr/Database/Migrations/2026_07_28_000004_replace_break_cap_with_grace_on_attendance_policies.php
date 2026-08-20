<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** (2026-07-28 audit) — make the break rule real, and stop duplicating the schedule. */
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
