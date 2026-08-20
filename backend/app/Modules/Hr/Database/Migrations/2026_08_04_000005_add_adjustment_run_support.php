<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Off-cycle adjustment runs — a way to pay a correction. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            // Why this run exists. Required for adjustments; null for regular and 13th-month runs,
            // whose reason for existing is the calendar.
            $table->string('reason', 500)->nullable()->after('type');
        });

        Schema::table('hr_payslips', function (Blueprint $table) {
            // The corrective amount, kept in its own column rather than folded into base_salary.
            // Back pay is not basic salary earned in the period this run covers, and putting it
            // there would quietly inflate the 13th-month basis, which is measured on base_salary.
            $table->decimal('adjustment_pay', 12, 2)->default(0)->after('thirteenth_month');
            // What this employee's line is for, so the payslip can explain itself.
            $table->string('adjustment_note', 500)->nullable()->after('adjustment_pay');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn(['adjustment_pay', 'adjustment_note']);
        });

        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
