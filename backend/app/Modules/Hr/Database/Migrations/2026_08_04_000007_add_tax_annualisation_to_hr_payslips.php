<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The year-end tax true-up, recorded on the payslip that carries it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            // Signed on purpose: positive = extra tax collected at year end, negative = refunded.
            $table->decimal('tax_annualisation', 12, 2)->default(0)->after('withholding_tax');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn('tax_annualisation');
        });
    }
};
