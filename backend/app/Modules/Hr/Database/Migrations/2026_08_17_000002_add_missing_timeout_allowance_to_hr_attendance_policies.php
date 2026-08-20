<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How long a shift may stay open past its scheduled end before it is treated as ABANDONED
 * rather than in progress (item 5 of the attendance/OT/ND fix queue).
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
