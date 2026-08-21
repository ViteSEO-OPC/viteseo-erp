<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Makes the `tiered` late rule REPEAT instead of charging once. Off is today's behaviour:
// [{"over":30,"hours":1}] charges a flat hour however late the arrival was. On, the rung
// becomes a repeating block — 30 min late = 1h, 60 min = 2h, 90 min = 3h.
//
// Deliberately a COLUMN rather than a key inside the late_tiers JSON: the payroll
// run-rules snapshot (PayrollService::policySnapshot) copies whole columns by name out of
// AttendancePolicyService::DEFAULTS, and the Rules-drift diff compares them field by field.
// Buried in the JSON, flipping this would show up as an opaque "late tiers changed".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->boolean('late_tier_recurring')->default(false)->after('late_tiers');
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->dropColumn('late_tier_recurring');
        });
    }
};
