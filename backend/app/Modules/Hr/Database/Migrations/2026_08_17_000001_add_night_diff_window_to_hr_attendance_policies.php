<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Make the night-differential band part of the dated attendance policy. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->time('night_diff_start_time')->default('22:00:00')->after('night_diff_pct');
            $table->time('night_diff_end_time')->default('06:00:00')->after('night_diff_start_time');
        });

        DB::table('hr_attendance_policy_versions')
            ->orderBy('id')
            ->each(function ($version): void {
                $payload = json_decode((string) $version->payload, true, flags: JSON_THROW_ON_ERROR);
                $payload['night_diff_start_time'] ??= '22:00:00';
                $payload['night_diff_end_time'] ??= '06:00:00';

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
                unset($payload['night_diff_start_time'], $payload['night_diff_end_time']);

                DB::table('hr_attendance_policy_versions')
                    ->where('id', $version->id)
                    ->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
            });

        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->dropColumn(['night_diff_start_time', 'night_diff_end_time']);
        });
    }
};
