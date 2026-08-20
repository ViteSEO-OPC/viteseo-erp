<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A ceiling on overtime nobody approved. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            // Per day. 0 = uncapped. A conventional company figure is 240 (4 hours), but see above:
            // it is HR's to set, not ours to default.
            $table->unsignedSmallInteger('auto_overtime_daily_cap_minutes')
                ->default(0)
                ->after('overtime_threshold_minutes');

            // Across the whole pay cycle, applied after the daily cap. 0 = uncapped. Guards the
            // pattern the daily cap can't see: many small unremarkable overruns adding up.
            $table->unsignedSmallInteger('auto_overtime_period_cap_minutes')
                ->default(0)
                ->after('auto_overtime_daily_cap_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->dropColumn([
                'auto_overtime_daily_cap_minutes',
                'auto_overtime_period_cap_minutes',
            ]);
        });
    }
};
