<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether working a rest day needs HR's permission first (Company → Rules).
 *
 * ON — the shipped default and the only behaviour that existed until now — is the rest-day
 * work request: pressing Time in on a day the schedule does not cover files a request instead
 * of a punch, and HR approving it stamps attendance at the moment of the press.
 *
 * OFF is for companies whose rest-day work is routine rather than exceptional — weekend
 * coverage rotas, skeleton shifts — where a queue that is always approved is a queue nobody
 * reads. The punch is then recorded immediately.
 *
 * NOTHING DOWNSTREAM CHANGES EITHER WAY. Rest-day premium is priced off any attendance row
 * landing on a non-workday (AttendanceService::restDayWorkedMinutesFor), not off an approval,
 * so turning the gate off pays exactly what an approved request paid. This switch decides who
 * has to agree before the row exists, and only that.
 *
 * Defaulted TRUE so an existing install behaves identically until HR opts out. Historical
 * policy versions are backfilled with the same value; safe by construction, because no pay
 * path reads this column — re-running an old cycle produces exactly the money it did before.
 */
return new class extends Migration
{
    private const DEFAULT = true;

    public function up(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->boolean('rest_day_work_requires_request')
                ->default(self::DEFAULT)
                ->after('early_clock_in_mode');
        });

        $this->backfillVersions(self::DEFAULT);
    }

    public function down(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->dropColumn('rest_day_work_requires_request');
        });

        $this->backfillVersions(null);
    }

    /** @param  bool|null  $value  null removes the key */
    private function backfillVersions(?bool $value): void
    {
        DB::table('hr_attendance_policy_versions')
            ->orderBy('id')
            ->each(function ($version) use ($value): void {
                $payload = json_decode((string) $version->payload, true, flags: JSON_THROW_ON_ERROR);

                if ($value === null) {
                    unset($payload['rest_day_work_requires_request']);
                } else {
                    $payload['rest_day_work_requires_request'] ??= $value;
                }

                DB::table('hr_attendance_policy_versions')
                    ->where('id', $version->id)
                    ->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
            });
    }
};
