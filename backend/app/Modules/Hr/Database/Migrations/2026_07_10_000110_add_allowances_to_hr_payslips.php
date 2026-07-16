<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Allowances (food + transportation + load) are part of pay: they're added to gross
// as a non-taxable de-minimis line. Stored on the payslip so the breakdown can show
// them and totals stay reproducible.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->decimal('allowances', 12, 2)->default(0)->after('reimbursements');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn('allowances');
        });
    }
};
