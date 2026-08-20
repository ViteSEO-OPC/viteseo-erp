<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Why the payslip's deduction figures are what they are. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            // [{date, kind, days, detail}] — every day the period charged for, and why.
            // The `days` sum to `unpaid_days`.
            $table->json('attendance_lines')->nullable()->after('late_penalty_days');
            // The withholding derivation: taxable pay, contributions netted off, the annualised
            // figure, the band it landed in, and how the year's tax came back to this period.
            $table->json('tax_basis')->nullable()->after('attendance_lines');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn(['attendance_lines', 'tax_basis']);
        });
    }
};
