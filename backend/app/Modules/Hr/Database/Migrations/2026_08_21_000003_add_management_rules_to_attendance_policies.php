<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The two company rules behind the management attendance exemption (Company → Settings).
// Both default to TODAY'S behaviour, so this migration changes nothing on its own.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            // ON (today's behaviour): management never punches — the platform writes their
            // days. OFF: the exemption stops entirely and they use the time clock like
            // everyone else, flags and all. Their PAY is unaffected either way: management is
            // salaried on a fixed basis, so attendance never prorates it.
            $table->boolean('management_auto_attendance')->default(true)->after('rest_day_work_requires_request');
            // Whether management earns the statutory premium for a holiday they worked.
            // OFF is today's behaviour and the reason the flag exists — fixed-salary staff are
            // not paid by the day. ON pays them the same premium as everyone else.
            $table->boolean('management_holiday_premium')->default(false)->after('management_auto_attendance');
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->dropColumn(['management_auto_attendance', 'management_holiday_premium']);
        });
    }
};
