<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Slice 14 (2026-07-28 audit) — reset the two policy modes whose values the engine never read.
 *
 * `missing_clockout_mode` and `early_arrival_mode` accepted several values through the API while
 * only one of each was implemented. Validation now rejects the unbuilt ones (see
 * UpdateAttendancePolicyRequest), so any row already holding one would make the Rules page
 * un-saveable: the form loads the stored value, sends it back, and gets a 422 on a field HR
 * can't even edit.
 *
 * No column changes — just bringing stored data in line with what the engine actually does.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('hr_attendance_policies')
            ->where('missing_clockout_mode', '!=', 'no_deduction_flag_for_review')
            ->update(['missing_clockout_mode' => 'no_deduction_flag_for_review']);

        DB::table('hr_attendance_policies')
            ->where('early_arrival_mode', '!=', 'none')
            ->update(['early_arrival_mode' => 'none']);
    }

    public function down(): void
    {
        // Nothing to reverse: the previous values were inert, so restoring them would only
        // recreate the mismatch between what is stored and what the engine honours.
    }
};
