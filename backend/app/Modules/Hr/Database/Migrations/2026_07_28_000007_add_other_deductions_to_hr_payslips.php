<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** (2026-07-28 audit) — a payslip line for non-statutory deductions. */
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
