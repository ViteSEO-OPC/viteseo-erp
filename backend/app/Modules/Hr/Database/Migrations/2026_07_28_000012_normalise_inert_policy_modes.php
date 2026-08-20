<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** (2026-07-28 audit) — reset the two policy modes whose values the engine never read. */
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
