<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Strict clock-in: when on, an employee cannot start their shift before its scheduled
// start time. Off (the default) keeps today's behaviour, where an early punch is
// recorded as-is and simply classified `present`.
//
// Deliberately NOT folded into the existing `early_arrival_mode`: that field is a PAYROLL
// question (do early minutes earn anything — currently inert, only 'none' is accepted),
// while this one governs whether the punch is accepted at all. One column each.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->boolean('block_early_clock_in')->default(false)->after('early_arrival_mode');
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->dropColumn('block_early_clock_in');
        });
    }
};
