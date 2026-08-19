<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How much auto-mode overtime in one day is enough to be worth a look (item 7 of the
 * attendance/OT/ND fix queue).
 *
 * A THIRD OVERTIME NUMBER, and deliberately not a fourth cap. The existing
 * `auto_overtime_*_cap_minutes` pair answers "how much pays without anyone approving it" and
 * WITHHOLDS the rest — inaction there means the employee is not paid. This one answers a
 * different question, "how much is unusual enough to show me", and withholds nothing: the day
 * pays in full either way and the row is a notification, not a gate. Conflating the two would
 * force a company that merely wants oversight to also start docking people.
 *
 * WHY IT IS NEEDED AT ALL. Both caps ship as 0 (uncapped), which is what every existing company
 * is on, and the review queue used to return nothing at all in that configuration — so on default
 * settings a nine-hour overrun from a clock-out corrected to the next morning paid in full with
 * nobody notified. This is the number that makes the queue useful before anyone configures a cap.
 *
 * Four hours by default: half a shift again, comfortably past the ordinary "stayed late to
 * finish something" overrun, and low enough to catch a mis-corrected punch on the same day it is
 * entered rather than on the payslip. 0 disables the flag without disabling the caps.
 *
 * Historical versions are backfilled with the same default. Safe by construction — this column
 * is read only by the review queue, never by any pay path, so re-running an old cycle produces
 * exactly the money it did before.
 */
return new class extends Migration
{
    private const DEFAULT_MINUTES = 240;

    public function up(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->unsignedSmallInteger('auto_overtime_review_minutes')
                ->default(self::DEFAULT_MINUTES)
                ->after('auto_overtime_period_cap_minutes');
        });

        DB::table('hr_attendance_policy_versions')
            ->orderBy('id')
            ->each(function ($version): void {
                $payload = json_decode((string) $version->payload, true, flags: JSON_THROW_ON_ERROR);
                $payload['auto_overtime_review_minutes'] ??= self::DEFAULT_MINUTES;

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
                unset($payload['auto_overtime_review_minutes']);

                DB::table('hr_attendance_policy_versions')
                    ->where('id', $version->id)
                    ->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
            });

        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->dropColumn('auto_overtime_review_minutes');
        });
    }
};
