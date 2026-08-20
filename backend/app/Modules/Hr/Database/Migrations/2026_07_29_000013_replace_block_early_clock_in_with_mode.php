<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Clocking in early stops being a yes/no and becomes three choices:
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->string('early_clock_in_mode', 10)->default('allow')->after('early_arrival_mode');
        });

        // Carry every existing policy across unchanged: on ⇒ block, off ⇒ allow. Nobody's
        // rules change meaning because of this migration.
        DB::table('hr_attendance_policies')
            ->where('block_early_clock_in', true)
            ->update(['early_clock_in_mode' => 'block']);

        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->dropColumn('block_early_clock_in');
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->boolean('block_early_clock_in')->default(false)->after('early_arrival_mode');
        });

        // `defer` has no boolean equivalent; it collapses to "not blocked", which is the
        // closest true statement about it.
        DB::table('hr_attendance_policies')
            ->where('early_clock_in_mode', 'block')
            ->update(['block_early_clock_in' => true]);

        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->dropColumn('early_clock_in_mode');
        });
    }
};
