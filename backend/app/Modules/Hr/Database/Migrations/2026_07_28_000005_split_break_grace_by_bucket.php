<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** (refinement) — separate tolerances for lunch and for breaks. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->unsignedSmallInteger('lunch_grace_minutes')->default(15)->after('hours_per_day_divisor');
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->dropColumn('lunch_grace_minutes');
        });
    }
};
