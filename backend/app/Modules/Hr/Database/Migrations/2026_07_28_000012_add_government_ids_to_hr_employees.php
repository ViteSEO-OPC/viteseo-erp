<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Government identifiers for the payslip header.
 *
 * NOT to be confused with the `sss_contribution` / `philhealth_contribution` /
 * `pagibig_contribution` columns already on this table: those are per-employee PESO OVERRIDES
 * that payroll uses instead of the company-config computation. These are the membership
 * NUMBERS, which move no money and exist purely so a payslip is a valid document — a PH
 * payslip is expected to carry TIN and the three agency numbers.
 *
 * Nullable with no backfill: nobody has captured these yet, and a made-up TIN is worse than a
 * blank one. The payslip renders an em dash until HR fills them in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->string('tin')->nullable()->after('philhealth_contribution');
            $table->string('sss_number')->nullable()->after('tin');
            $table->string('philhealth_number')->nullable()->after('sss_number');
            $table->string('pagibig_number')->nullable()->after('philhealth_number');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn(['tin', 'sss_number', 'philhealth_number', 'pagibig_number']);
        });
    }
};
