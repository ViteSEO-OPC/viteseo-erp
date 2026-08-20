<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How much auto-mode overtime in one day is enough to be worth a look (item 7 of the
 * attendance/OT/ND fix queue).
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
