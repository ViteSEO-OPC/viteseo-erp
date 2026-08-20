<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-employee AM/PM half-day windows, so the Job tab can override them like every other
 * schedule field.
 *
 * Until now the half-day windows were the ONE part of a schedule with no per-employee override —
 * `ScheduleService::effectiveFor()` read them straight off the template, and the Job tab said as
 * much ("Half-day windows are set on the schedule, not per employee"). That made a half day
 * unrepresentable for anyone whose hours differ from their template's: the employee on a
 * 13:30 afternoon start was judged against the template's 13:00, and every minute between the two
 * was billable lateness.
 *
 * NULLABLE, AND NULL MEANS INHERIT — the same contract as `custom_start_time` and the rest.
 * Blank falls through to the assigned schedule field by field, so overriding a PM start without a
 * PM end keeps the template's end rather than being rejected.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'custom_am_start_time',
        'custom_am_end_time',
        'custom_pm_start_time',
        'custom_pm_end_time',
    ];

    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $after = 'custom_end_time';
            foreach (self::COLUMNS as $column) {
                $table->time($column)->nullable()->after($after);
                $after = $column;
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
