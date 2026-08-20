<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Final pay — the terminal payslip a leaver is owed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            // Entered by HR. Nullable rather than defaulted so "not applicable" and "zero" differ.
            $table->decimal('separation_pay', 12, 2)->nullable()->after('adjustment_note');
            // False = exempt (the involuntary-separation case). See the class docblock.
            $table->boolean('separation_pay_taxable')->default(false)->after('separation_pay');
            // Unused leave converted to cash, and the days it represents — the days are kept so the
            // payslip can show the derivation rather than one opaque peso figure.
            $table->decimal('leave_conversion', 12, 2)->nullable()->after('separation_pay_taxable');
            $table->decimal('leave_conversion_days', 6, 2)->nullable()->after('leave_conversion');
            // Outstanding loan balance recovered from this payslip, so the settlement is visible
            // rather than buried in `other_deductions`.
            $table->decimal('loan_settlement', 12, 2)->nullable()->after('leave_conversion_days');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn([
                'separation_pay', 'separation_pay_taxable',
                'leave_conversion', 'leave_conversion_days', 'loan_settlement',
            ]);
        });
    }
};
