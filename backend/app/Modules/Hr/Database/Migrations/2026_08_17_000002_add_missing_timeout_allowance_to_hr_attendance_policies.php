<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How long a shift may stay open past its scheduled end before it is treated as ABANDONED
 * rather than in progress (item 5 of the attendance/OT/ND fix queue).
 *
 * A DURATION, NOT A CLOCK TIME, and that is the whole point. The obvious shape — "close every
 * open record at 22:00" — recreates the midnight bug at a different hour: a 22:00–06:00 shift
 * would be cut off before it began. Counting forward from each employee's OWN scheduled end
 * gives the day worker a 21:00 cutoff and the graveyard worker a 06:00 one, from one setting.
 *
 * LOAD-BEARING EVEN THOUGH NOTHING IS WRITTEN. The abandoned end is derived at read time, never
 * stamped (see AttendanceService::workedWindowFor). Without this allowance a mid-cycle payroll
 * PREVIEW would resolve an end for someone who is still on shift right now and pay hours they
 * have not worked yet. The allowance is what separates "forgot to punch out" from "still here".
 *
 * Three hours by default: long enough to cover a normal unapproved overrun, short enough that a
 * cycle closing the next morning still prices the day correctly.
 *
 * Historical versions are backfilled with the same default. That does mean re-running an old,
 * still-open cycle now pays the night differential it should always have paid — which is the
 * point of the fix. Cycles that actually went out are protected by PayrollFreezeService, not by
 * this column, so no disbursed payslip can move.
 */
return new class extends Migration
{
    private const DEFAULT_MINUTES = 180;

    public function up(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->unsignedSmallInteger('missing_timeout_allowance_minutes')
                ->default(self::DEFAULT_MINUTES)
                ->after('missing_clockout_mode');
        });

        DB::table('hr_attendance_policy_versions')
            ->orderBy('id')
            ->each(function ($version): void {
                $payload = json_decode((string) $version->payload, true, flags: JSON_THROW_ON_ERROR);
                $payload['missing_timeout_allowance_minutes'] ??= self::DEFAULT_MINUTES;

                DB::table('hr_attendance_policy_versions')
                    ->where('id', $version->id)
                    ->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
            });
    }

    public function down(): void
    {
        DB::table('hr_attendance_policy_versions')
            ->orderBy('id')
            ->each(function ($version): void {
                $payload = json_decode((string) $version->payload, true, flags: JSON_THROW_ON_ERROR);
                unset($payload['missing_timeout_allowance_minutes']);

                DB::table('hr_attendance_policy_versions')
                    ->where('id', $version->id)
                    ->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
            });

        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->dropColumn('missing_timeout_allowance_minutes');
        });
    }
};
