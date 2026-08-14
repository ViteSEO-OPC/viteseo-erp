<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why the payslip's deduction figures are what they are.
 *
 * The document already carried the TOTALS — unpaid days, late minutes, withholding tax — and
 * nothing at all about how any of them was arrived at. "2.5 unpaid days" is unanswerable to the
 * person reading it: they cannot tell which days, and the only way to check was to ask HR to
 * open the attendance roster next to them.
 *
 * Both columns are SNAPSHOTS, for the same reason `contribution_bases` and `policy_snapshot`
 * are. Deriving either at read time would mean a payslip that explains itself differently after
 * an attendance correction or a tax-table edit — the figure printed on the document staying put
 * while the explanation beneath it moved.
 *
 * Null on every payslip written before this migration. Nothing is backfilled: the ledger can only
 * be produced by the run that priced the period, and inventing one from today's attendance is the
 * exact failure the snapshot exists to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            // [{date, kind, days, detail}] — every day the period charged for, and why.
            // The `days` sum to `unpaid_days`.
            $table->json('attendance_lines')->nullable()->after('late_penalty_days');
            // The withholding derivation: taxable pay, contributions netted off, the annualised
            // figure, the band it landed in, and how the year's tax came back to this period.
            $table->json('tax_basis')->nullable()->after('attendance_lines');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn(['attendance_lines', 'tax_basis']);
        });
    }
};
