<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Lateness deductions split into two INDEPENDENT switches (2026-07-27):
//   1. per-time deduction  → late_deduction_mode ('none' | 'per_hour' | 'tiered')
//   2. per-N-lates penalty → the new `late_penalty_enabled` boolean + lates_per_deduction
//
// Previously these were one mutually-exclusive enum, so a company could not BOTH charge
// the actual minutes late AND add a full-day penalty for every N lates. They now compose.
//
// No-op by default: late_penalty_enabled defaults false, so nothing changes until HR turns
// it on. Any existing row still on the retired 'per_lates' enum is migrated to
// (enabled=true, mode='none') so its behaviour is preserved exactly.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->boolean('late_penalty_enabled')->default(false)->after('lates_per_deduction');
        });

        // Preserve any policy that used the retired 'per_lates' mode.
        DB::table('hr_attendance_policies')
            ->where('late_deduction_mode', 'per_lates')
            ->update(['late_penalty_enabled' => true, 'late_deduction_mode' => 'none']);
    }

    public function down(): void
    {
        // Best-effort inverse: fold the penalty switch back into the single enum. A row that
        // had BOTH the per-time mode and the penalty on cannot be represented in the old
        // model, so the penalty wins (matches the pre-split precedence).
        DB::table('hr_attendance_policies')
            ->where('late_penalty_enabled', true)
            ->update(['late_deduction_mode' => 'per_lates']);

        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->dropColumn('late_penalty_enabled');
        });
    }
};
