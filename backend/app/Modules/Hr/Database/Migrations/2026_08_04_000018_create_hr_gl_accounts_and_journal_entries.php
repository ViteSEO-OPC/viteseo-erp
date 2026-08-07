<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll reaching the books — a chart of accounts and a journal per run (FIN-043 / MF-05).
 *
 * Payroll expense, employer statutory cost, and every payable it creates existed only inside
 * `hr_payslips`. Nothing turned a run into the double-entry the general ledger needs, so
 * payroll-to-accounting reconciliation was impossible by construction: the two systems held the same
 * money in incompatible shapes and no artifact connected them.
 *
 * THE ENTRY IS DERIVED FROM THE RUN, THEN FROZEN. `JournalEntryService` computes the lines from the
 * payslips and stores them, rather than deriving on read like the arrears and loan ledgers do. That is a
 * deliberate departure from this codebase's derive-don't-store default, and the reason is what a journal
 * IS: a posted entry is a statement about what the books said on a date. If it re-derived, correcting a
 * payslip six months later would silently rewrite last quarter's ledger, and the ledger would stop
 * agreeing with anything already filed from it. So the entry is a snapshot, and changing it takes a
 * reversal.
 *
 * WHICH MEANS REVERSAL, NOT DELETION. `reverse()` writes a mirror entry with the debits and credits
 * swapped and links the two. Deleting a posted journal would destroy the record that it had ever been
 * posted, and the duplicate-posting guard depends on that record existing.
 *
 * DEBITS MUST EQUAL CREDITS, and the service refuses to post otherwise. The identity it relies on holds
 * for every payslip this system writes:
 *
 *     net_pay = gross − (sss + philhealth + pagibig + withholding_tax + other_deductions)
 *
 * so `DR gross + DR employer shares` equals `CR each payable + CR net pay payable`. A run that cannot
 * balance is a bug in payroll, and posting an unbalanced journal would hide it inside accounting.
 *
 * A NEGATIVE FIGURE FLIPS SIDES rather than posting as a negative credit. Withholding tax genuinely goes
 * negative — a year-end true-up or a mid-year leaver's final pay is usually a refund (FIN-028, FIN-014) —
 * and "CR −₱4,000" is not a journal line any ledger will accept.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The chart of accounts. Deliberately NOT seeded with real account codes: those come from the
        // company's existing ledger, and inventing "5100 — Salaries Expense" would produce a journal
        // that posts cleanly and reconciles to nothing. The service refuses to build an entry until the
        // roles below are mapped to real accounts.
        Schema::create('hr_gl_accounts', function (Blueprint $table) {
            $table->id();
            // The company's own code, as it appears in their ledger.
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('type'); // expense | liability | asset
            /**
             * WHICH PAYROLL FIGURE THIS ACCOUNT RECEIVES — the join between payroll and the ledger.
             *
             * A fixed vocabulary rather than free text, because the generator looks accounts up by it:
             * salaries_expense, employer_contributions_expense, sss_payable, philhealth_payable,
             * pagibig_payable, withholding_tax_payable, other_deductions_payable, net_pay_payable.
             * Nullable so a company can also hold accounts here that payroll does not post to.
             */
            $table->string('payroll_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // One account per role: two accounts claiming `net_pay_payable` would make the generator's
            // choice arbitrary, and arbitrary is the one thing a ledger mapping cannot be.
            $table->unique('payroll_role');
        });

        Schema::create('hr_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
            /**
             * The date the entry hits the books, which is NOT the run date and not the pay date.
             * Accounting periods close; an entry has to be able to land in the period it belongs to
             * even when it is generated afterwards.
             */
            $table->date('posting_date');
            $table->string('reference')->nullable(); // the company's own journal reference
            // draft: computed, editable, not on the books. posted: on the books, immutable.
            // reversed: still on the books, cancelled by `reversal_of_id`'s mirror.
            $table->string('status')->default('draft');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            // Set on the MIRROR entry, pointing at what it reverses. The original keeps status
            // `reversed`, so both halves of the correction stay visible.
            $table->foreignId('reversal_of_id')->nullable()->constrained('hr_journal_entries')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['payroll_run_id', 'status']);
        });

        Schema::create('hr_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('hr_journal_entries')->cascadeOnDelete();
            $table->foreignId('gl_account_id')->constrained('hr_gl_accounts')->cascadeOnDelete();
            // One side is always zero. Storing both rather than a signed amount is the convention
            // every accounting export and every accountant expects to read.
            $table->decimal('debit', 12, 2)->default(0);
            $table->decimal('credit', 12, 2)->default(0);
            $table->string('memo')->nullable();
            $table->timestamps();

            $table->index('journal_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_journal_lines');
        Schema::dropIfExists('hr_journal_entries');
        Schema::dropIfExists('hr_gl_accounts');
    }
};
