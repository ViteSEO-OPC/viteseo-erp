<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 13a item 1 — make the payslip add up.
 *
 * `net_pay` was floored at 0 but `total_deductions` was not, so a heavily-prorated payslip could
 * read gross ₱2,000 − deductions ₱3,000 = net ₱0. The figures didn't reconcile and the ₱1,000
 * that couldn't be collected vanished silently.
 *
 * Deductions are now capped at gross and the uncollected remainder is recorded here, so HR can
 * see what still needs recovering instead of it disappearing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->decimal('deduction_carryover', 12, 2)->default(0)->after('total_deductions');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn('deduction_carryover');
        });
    }
};
