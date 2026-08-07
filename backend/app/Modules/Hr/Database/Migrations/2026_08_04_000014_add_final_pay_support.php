<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Final pay — the terminal payslip a leaver is owed (FIN-014).
 *
 * WHAT ALREADY WORKED, and what this does NOT duplicate. Slice 4 already pulls a leaver into their
 * final regular cycle with `withTrashed()` and prorates the days they worked. That half was fine.
 * Everything else was missing: no separation pay, no conversion of unused leave to cash, no forced
 * settlement of outstanding loans, no tax annualisation on the terminal payslip. Outstanding loans
 * simply stopped being collected when the employee left, because `collectForPeriod()` only ever runs
 * against a pay group the employee is no longer in.
 *
 * So a final-pay run pays the TERMINAL amounts and leaves the last cycle's basic salary to the
 * regular run that already handles it. Two runs, each doing one thing, rather than one run that has
 * to know whether the last cycle has been processed yet.
 *
 * SEPARATION PAY IS ENTERED, NOT COMPUTED — a deliberate scope line. Articles 298–299 set different
 * formulas for retrenchment, redundancy, closure and disease, the entitlement turns on facts the
 * system does not hold (was the closure due to serious losses?), and a just-cause dismissal carries
 * none at all. Encoding multipliers nobody has signed off would be the same mistake as guessing a
 * de-minimis ceiling (FIN-024) or a minimum wage (FIN-030), except the output is a payment rather
 * than a warning. HR enters the amount; the system records who entered it and taxes it correctly.
 * Computed formulas can layer on top of this later without changing the schema.
 *
 * `separation_pay_taxable` EXISTS BECAUSE THE EXEMPTION TURNS ON THE CAUSE. Separation for a cause
 * beyond the employee's control — retrenchment, redundancy, closure, death, sickness — is exempt
 * under NIRC §32(B)(6)(b); a voluntary resignation package is not. HR knows which; the system cannot
 * infer it. Defaulting to exempt matches the common case (involuntary separation) and the flag makes
 * the other one expressible instead of silently wrong.
 */
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
