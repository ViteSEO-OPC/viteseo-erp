<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Whether working a rest day needs HR's permission first (Company → Rules). */
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
