<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which obligation went uncollected when pay could not cover the deductions. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            // {sss, philhealth, pagibig, withholding_tax, other_deductions} => uncollected amount.
            // Null on payslips issued before the components were capped, and on any payslip where
            // gross covered the deductions in full — there is nothing to itemise.
            $table->json('deduction_carryover_breakdown')->nullable()->after('deduction_carryover');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn('deduction_carryover_breakdown');
        });
    }
};
