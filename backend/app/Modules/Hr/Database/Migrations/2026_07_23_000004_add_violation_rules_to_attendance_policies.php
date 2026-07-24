<?php

use App\Modules\Hr\Services\AttendancePolicyService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Slice 1, step 3c (audit finding P1-5). The violation severity ladder is hard-coded in
// ViolationService::recompute() (:167-168, :183, :186, :196) and `awol_days_threshold` is a
// DEAD field — validated, seeded and shown in the UI, but read by no service. This column
// makes the ladder data so HR decides what counts as green/yellow/orange/red.
//
// One JSON column rather than ~15 scalars: a ladder is a LIST of rungs, and a company may
// want three or five. Seeded (in AttendancePolicyService) with today's exact literals, so
// nothing changes until ViolationService is taught to read it (a later slice).
//
// Severity names stay green|yellow|orange|red — Violation::SEVERITY_RANK and every UI badge
// depend on them. HR-facing labels (Warning/Critical) would be display-only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->json('violation_rules')->nullable()->after('over_max_break_action');
        });

        // Backfill the EXISTING policy row: firstOrCreate() only applies defaults when it
        // creates, so an install that already has the singleton would be left null here.
        DB::table('hr_attendance_policies')
            ->whereNull('violation_rules')
            ->update(['violation_rules' => json_encode(AttendancePolicyService::DEFAULT_VIOLATION_RULES)]);
    }

    public function down(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->dropColumn('violation_rules');
        });
    }
};
