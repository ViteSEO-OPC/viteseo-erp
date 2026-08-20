<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

//  (attendance→pay). Extends the EXISTING work-schedule template so the paid
// day, breaks, and half-day windows are HR-editable data — not a hard-coded constant.
//   - unpaid_break_minutes: the meal period, SUBTRACTED from the paid day (denominator).
//   - paid_break_minutes: compensable short break, INSIDE the paid day (flag threshold only).
//   - am_/pm_*_time: the half-day windows, so a half-day's hours are known, not guessed.
// All non-null defaults / nullable, so a plain `migrate` on existing rows is a no-op.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_work_schedules', function (Blueprint $table) {
            $table->unsignedSmallInteger('unpaid_break_minutes')->default(60)->after('grace_minutes');
            $table->unsignedSmallInteger('paid_break_minutes')->default(20)->after('unpaid_break_minutes');
            $table->time('am_start_time')->nullable()->after('paid_break_minutes');
            $table->time('am_end_time')->nullable()->after('am_start_time');
            $table->time('pm_start_time')->nullable()->after('am_end_time');
            $table->time('pm_end_time')->nullable()->after('pm_start_time');
        });
    }

    public function down(): void
    {
        Schema::table('hr_work_schedules', function (Blueprint $table) {
            $table->dropColumn([
                'unpaid_break_minutes',
                'paid_break_minutes',
                'am_start_time',
                'am_end_time',
                'pm_start_time',
                'pm_end_time',
            ]);
        });
    }
};
