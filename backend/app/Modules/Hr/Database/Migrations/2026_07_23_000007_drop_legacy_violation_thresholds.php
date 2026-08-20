<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Violation ladder. The colour thresholds now live in the
// `violation_rules` ladder, which ViolationService reads. Two legacy scalar fields are
// retired so there is ONE source of truth (the audit's "don't leave both"):
//   - awol_days_threshold: was a DEAD field — validated/seeded/shown but read by nobody.
//     Its role (absence → red at N days) is now the ladder's absence red rung.
//   - consecutive_lates_before_absence: only drove a misleading note ("counts as an
//     absence") for a conversion that never happened. Removed with the note.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->dropColumn(['awol_days_threshold', 'consecutive_lates_before_absence']);
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->unsignedSmallInteger('consecutive_lates_before_absence')->default(3)->after('id');
            $table->unsignedSmallInteger('awol_days_threshold')->default(3)->after('monthly_late_reset');
        });
    }
};
