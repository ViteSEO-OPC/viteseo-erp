<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrections land ON the payslip of the period they correct, instead of on a payslip of their own.
 *
 * WHAT CHANGED AND WHY. FIN-010's adjustment run paid a correction by creating a whole separate run
 * and a second payslip. It worked, but it produced two documents for one period that referenced
 * each other nowhere: the employee got a March payslip saying ₱20,000 and, weeks later, an
 * unexplained ₱1,000 payslip dated April. Nobody reading either could tell that the first was
 * wrong, or that the second was the rest of it.
 *
 * So an amendment now adds a line to the period's OWN payslip. One document per period, with its
 * corrections on it — which is how an amended payslip works everywhere outside this system.
 *
 * THE PART THAT MAKES THAT SAFE IS `disbursed_amount`. Amending a payslip changes its net, and a
 * payslip that had already been paid would then claim a figure that never left the bank: status
 * `disbursed`, net ₱21,000, ₱20,000 actually transferred. Every register, report and total would
 * inherit that. So the amount actually disbursed is frozen on the payslip at the moment it is paid,
 * and what is still owed is `net_pay − disbursed_amount` — a derived balance, not a stored one, for
 * the same reason `deductionArrearsFor()` derives arrears rather than decrementing a column.
 *
 * THE AMENDMENT LEDGER IS THE SOURCE OF TRUTH, not `adjustment_pay`. That column is the running
 * total, kept for the payslip document and for every query that already reads it; the rows here are
 * what it is a total OF. It has to work that way because a period can be RE-RUN: recomputing a
 * payslip rewrites every earnings column from attendance, which would silently erase a correction
 * unless the corrections can be re-applied from a record the recompute does not own. Each row
 * therefore carries the tax that was withheld on it as well as the amount, so re-applying it
 * reproduces what was actually withheld rather than re-deciding it against today's YTD.
 *
 * `tax_stale_at` ON THE RUN is the other half. Withholding on a 13th-month, a final pay, an
 * adjustment and December's year-end true-up are all computed against the year's earlier payslips.
 * Amending an earlier period moves that basis, so any later run of those kinds is now settled
 * against numbers that no longer exist. December re-reads the year live, so this is invisible right
 * up until it matters — the run is therefore marked, rather than left for someone to notice.
 */
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
