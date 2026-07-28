<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 11a (2026-07-28 audit) — a payslip line for non-statutory deductions.
 *
 * Payroll could only ever deduct SSS / PhilHealth / Pag-IBIG / withholding tax. An employee's
 * share of an HMO or union dues — already configured in Company → Benefits as
 * `hr_benefit_enrollments.employee_share` — was never taken.
 *
 * The breakdown is stored alongside the total so a payslip can name what was deducted instead
 * of showing one opaque figure, the same reasoning as the step-4b late/undertime columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->decimal('other_deductions', 12, 2)->default(0)->after('withholding_tax');
            $table->json('other_deductions_breakdown')->nullable()->after('other_deductions');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn(['other_deductions', 'other_deductions_breakdown']);
        });
    }
};
