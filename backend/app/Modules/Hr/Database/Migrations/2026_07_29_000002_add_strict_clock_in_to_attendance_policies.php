<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Strict clock-in: when on, an employee cannot start their shift before its scheduled
// start time. Off (the default) keeps today's behaviour, where an early punch is
// recorded as-is and simply classified `present`.
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
