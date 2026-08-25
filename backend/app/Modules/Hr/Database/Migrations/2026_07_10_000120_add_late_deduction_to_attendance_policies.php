<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Payroll tardiness policy (Company → Settings): how accumulated lates translate into a
// pay deduction. Kept SEPARATE from the violation-flag thresholds so HR can tune the
// colour ladder and the payroll penalty independently. Default 'none' — no behaviour
// change until HR opts in.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            // 'none' | 'per_lates'
            $table->string('late_deduction_mode')->default('none')->after('monthly_late_reset');
            // per_lates: every N lates in a pay period deducts one day's pay.
            $table->unsignedSmallInteger('lates_per_deduction')->default(3)->after('late_deduction_mode');
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->dropColumn(['late_deduction_mode', 'lates_per_deduction']);
        });
    }
};
