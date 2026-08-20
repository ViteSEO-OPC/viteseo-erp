<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Step 4b — BLOCKER for step 7 (payslip breakdown display).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            // The inputs that produced base_salary — the "show your working" columns.
            $table->unsignedSmallInteger('working_days')->default(0)->after('base_salary');
            $table->decimal('daily_rate', 12, 2)->default(0)->after('working_days');
            $table->decimal('unpaid_days', 6, 2)->default(0)->after('daily_rate');

            // The minute-derived portion, split for display (step 7).
            $table->unsignedSmallInteger('late_minutes')->default(0)->after('unpaid_days');
            $table->unsignedSmallInteger('undertime_minutes')->default(0)->after('late_minutes');
            $table->decimal('late_deduction', 12, 2)->default(0)->after('undertime_minutes');
            $table->decimal('undertime_deduction', 12, 2)->default(0)->after('late_deduction');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn([
                'working_days',
                'daily_rate',
                'unpaid_days',
                'late_minutes',
                'undertime_minutes',
                'late_deduction',
                'undertime_deduction',
            ]);
        });
    }
};
