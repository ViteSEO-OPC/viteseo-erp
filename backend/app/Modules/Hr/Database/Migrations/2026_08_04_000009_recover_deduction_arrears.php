<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Uncollected deductions are recovered from later periods. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            // Total arrears recovered on this payslip, on top of its own period's obligations.
            $table->decimal('arrears_recovered', 12, 2)->default(0)->after('deduction_carryover_breakdown');
            // obligation => amount recovered. Null when nothing was recovered, which is almost
            // every payslip — the same shape as the carryover breakdown it settles.
            $table->json('arrears_recovered_breakdown')->nullable()->after('arrears_recovered');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn(['arrears_recovered', 'arrears_recovered_breakdown']);
        });
    }
};
