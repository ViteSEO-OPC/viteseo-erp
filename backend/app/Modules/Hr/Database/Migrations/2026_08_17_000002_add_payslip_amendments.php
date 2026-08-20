<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Corrections land ON the payslip of the period they correct, instead of on a payslip of their own. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payslip_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained('hr_payslips')->cascadeOnDelete();

            // Positive only, as the adjustment run was. Recovering an overpayment is a wage
            // deduction needing written consent under Art. 113 and is its own process.
            $table->decimal('amount', 12, 2);
            // What was withheld on this amount, computed marginally on top of the year's earnings
            // at the time it was raised. Stored, not derived — see the ledger note above.
            $table->decimal('tax', 12, 2)->default(0);

            // Why the batch exists, and what this employee's share of it is for. Both kept: the
            // reason is the batch's ("back pay for the 1–15 March cutoff"), the note is the line's.
            $table->string('reason', 500);
            $table->string('note', 500)->nullable();

            // WHEN THE CORRECTION WAS ISSUED — the date the payslip shows beside the line, and the
            // only thing that distinguishes two amendments to the same period. Not the period being
            // corrected: that is the payslip this hangs off.
            $table->date('issued_on');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            // Soft-deleted like every other HR record: a reversed correction is history too.
            $table->softDeletes();

            // How this is always read — every amendment on a payslip, oldest first.
            $table->index(['payslip_id', 'deleted_at']);
        });

        Schema::table('hr_payslips', function (Blueprint $table) {
            // What actually left the company for this payslip, frozen when it was marked paid.
            // Null while unpaid, and on nothing else: a disbursed payslip always has one.
            $table->decimal('disbursed_amount', 12, 2)->nullable()->after('net_pay');
        });

        // Every payslip already paid was paid in full — there was no way to amend one until now.
        // Without this backfill they would all read as fully outstanding the moment the balance is
        // shown, which is the opposite of the truth.
        DB::table('hr_payslips')
            ->where('status', 'disbursed')
            ->update(['disbursed_amount' => DB::raw('net_pay')]);

        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            // Set when an earlier period is amended and this run's withholding was computed against
            // it. Cleared by recomputing the run, which is the only thing that fixes it.
            $table->timestamp('tax_stale_at')->nullable()->after('policy_snapshot');
            $table->string('tax_stale_reason', 500)->nullable()->after('tax_stale_at');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $table->dropColumn(['tax_stale_at', 'tax_stale_reason']);
        });

        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn('disbursed_amount');
        });

        Schema::dropIfExists('hr_payslip_amendments');
    }
};
