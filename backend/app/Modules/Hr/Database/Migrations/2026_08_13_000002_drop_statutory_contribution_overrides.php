<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the three per-employee statutory amounts.
 *
 * WHY THEY GO. Every employee is charged the company rates for their bracket — that computation is
 * uniform by design, and these columns were the one thing that could break it per person. Worse,
 * an amount here replaced only the EMPLOYEE side of the contribution: the employer half went on
 * following the brackets, so a single payslip could carry a pair that exists on no SSS table
 * (₱1,350 employee against ₱3,530 employer) and a remittance built from it could not be filed.
 *
 * Un-enrolling someone (hr_employee_benefits) remains the supported way to say a person does not
 * pay a given contribution. That is a statement the remittance can represent; a different amount
 * for the same salary bracket never was.
 *
 * SAFE TO DROP: every row is null. Nothing wrote them but the employee form, which is gone in the
 * same change, and payroll no longer reads them.
 *
 * `hr_payslips.contribution_overrides` is deliberately NOT dropped. It records which contributions
 * on an ALREADY ISSUED payslip came from an override, and payslips are historical documents — a
 * mismatch that was real when the slip was cut must stay visible on it afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn(['sss_contribution', 'pagibig_contribution', 'philhealth_contribution']);
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->decimal('sss_contribution', 12, 2)->nullable()->after('bank_account_name');
            $table->decimal('pagibig_contribution', 12, 2)->nullable()->after('sss_contribution');
            $table->decimal('philhealth_contribution', 12, 2)->nullable()->after('pagibig_contribution');
        });
    }
};
