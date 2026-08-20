<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Premium Pay epic — Phase 0 / (2026-07-27). HR-editable premium & overtime rates. Each is a
// whole-percent multiplier of the hourly/daily rate (e.g. 200 = ×2.0), EXCEPT night_diff_pct, which
// is the ADD-ON percent that stacks (10 = +10%). Every default equals its DOLE legal minimum, so this
// migration moves no pesos — nothing reads these yet. Validation hard-floors each field at its minimum.
// Consumers land later: holiday premium, overtime (Phase 1), night diff (Phase 2), rest-day (Phase 3).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            // First-8h premiums for WORKING the day (total % of the day's pay).
            $table->unsignedSmallInteger('premium_rest_day_pct')->default(130)->after('overtime_threshold_minutes');
            $table->unsignedSmallInteger('premium_special_day_pct')->default(130)->after('premium_rest_day_pct');
            $table->unsignedSmallInteger('premium_regular_holiday_pct')->default(200)->after('premium_special_day_pct');
            // Overtime rates (total % of the hourly rate, applied to OT hours).
            $table->unsignedSmallInteger('overtime_ordinary_pct')->default(125)->after('premium_regular_holiday_pct');
            $table->unsignedSmallInteger('overtime_rest_special_pct')->default(169)->after('overtime_ordinary_pct');
            $table->unsignedSmallInteger('overtime_regular_holiday_pct')->default(260)->after('overtime_rest_special_pct');
            // Night differential — ADD-ON percent for 22:00–06:00 work (10 = +10%), stacks on the above.
            $table->unsignedSmallInteger('night_diff_pct')->default(10)->after('overtime_regular_holiday_pct');
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->dropColumn([
                'premium_rest_day_pct',
                'premium_special_day_pct',
                'premium_regular_holiday_pct',
                'overtime_ordinary_pct',
                'overtime_rest_special_pct',
                'overtime_regular_holiday_pct',
                'night_diff_pct',
            ]);
        });
    }
};
