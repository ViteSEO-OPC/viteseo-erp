<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An intern's payslip, remembered as one.
 *
 * An intern is paid an allowance in the same columns a salary uses, so nothing about the figures
 * says which it was. `is_intern` does — snapshot on the slip rather than read off the employee,
 * because a converted intern's old payslips must keep printing as intern payslips, keep staying out
 * of the 13th-month basis, and keep refusing a tax correction, long after their status says staff.
 *
 * `intern_basis` records how the allowance was reached: the method, the amount and whose it was,
 * the daily and hourly equivalents, and the rules version it was priced under.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->boolean('is_intern')->default(false)->after('employee_id');
            $table->json('intern_basis')->nullable()->after('is_intern');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn(['is_intern', 'intern_basis']);
        });
    }
};
