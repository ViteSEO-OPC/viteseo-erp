<?php

use App\Support\CurrentOrg;
use App\Modules\Hr\Services\AttendancePolicyService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Step 3c (audit). The violation severity ladder is hard-coded in
// ViolationService::recompute() (:167-168, :183, :186, :196) and `awol_days_threshold` is a
// DEAD field — validated, seeded and shown in the UI, but read by no service. This column
// makes the ladder data so HR decides what counts as green/yellow/orange/red.
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Scope off for the duration. This is a DATA migration: it writes through
         * Eloquent models, and those models now carry the tenant scope, which
         * filters on a column 2026_09_03_100002 has not added yet at this point in
         * the history. See CurrentOrg::suspendScope().
         */
        CurrentOrg::suspendScope(function (): void {
            Schema::table('hr_attendance_policies', function (Blueprint $table) {
                $table->json('violation_rules')->nullable()->after('over_max_break_action');
            });

            // Backfill the EXISTING policy row: firstOrCreate() only applies defaults when it
            // creates, so an install that already has the singleton would be left null here.
            DB::table('hr_attendance_policies')
                ->whereNull('violation_rules')
                ->update(['violation_rules' => json_encode(AttendancePolicyService::DEFAULT_VIOLATION_RULES)]);
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->dropColumn('violation_rules');
        });
    }
};
