<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the money actually went (FIN-015).
 *
 * THE GAP. Payroll computed net pay correctly and then stopped tracking it. "Disbursement" was a
 * single manual status flip per payslip (`markDone()`) — no payment record, no bank reference, no
 * failure handling, no reconciliation. Bank details were collected on every employee and never
 * used. The money left the company through a channel the system could not see, and the system held
 * no evidence of when, or whether, an employee was actually paid.
 *
 * THREE STATES, NOT TWO. The audit's terminology, which these tables exist to make real:
 *
 *   computed  — a payslip exists
 *   disbursed — HR asserted payment
 *   settled   — money confirmed received
 *
 * Only the first two were establishable before this. A payment row is what carries the third: the
 * bank's reference, the date it cleared, and — the half that matters most in practice — the date it
 * did NOT clear and why. A returned transfer used to be invisible; the payslip sat `disbursed`
 * regardless.
 *
 * WHY A BATCH AND NOT JUST PAYMENTS. Banks are paid in files, not in rows. The batch is the unit
 * that gets generated, downloaded, uploaded to the bank and reconciled back, so it needs its own
 * identity, total and export timestamp. It also gives the one assertion worth making about a
 * payroll disbursement: the batch total must equal the run's `total_net`, and every payslip in the
 * run must appear exactly once. A batch that does not satisfy both is a payroll someone will be
 * chasing at month end.
 *
 * THE ACCOUNT IS SNAPSHOTTED, NOT REFERENCED. `hr_payments` copies the employee's bank details at
 * the moment the batch is built rather than joining to `hr_employees`. An employee who changes
 * bank next month must not retroactively change where last month's money went — the payment record
 * is evidence of a transfer that already happened, and evidence that mutates is not evidence. This
 * is the same reasoning as the dated salary history and the run's policy snapshot.
 *
 * THOSE SNAPSHOT COLUMNS ARE ENCRYPTED, and the employee table's are not — a deliberate
 * inconsistency. FIN-022 flags plaintext bank accounts and government IDs as a High finding, and
 * fixing it on `hr_employees` is Slice 6 work with a data migration behind it. This table is new
 * and has no legacy rows, so encrypting here costs nothing and is strictly better than minting a
 * second plaintext copy of the most sensitive column in the system while the first one is already
 * a known finding. When Slice 6 encrypts the employee table, these match rather than needing a
 * second pass. Encrypted values are opaque to SQL — nothing aggregates or searches on them, and
 * nothing should.
 *
 * WHAT THIS MIGRATION DOES NOT DO. It does not touch `markDone()`. That path stays as the manual
 * assertion it always was, because a company paying by cash or cheque still needs it and because
 * removing it would strand every payslip disbursed before these tables existed. Reconciliation
 * (the import that flips payslips to `disbursed` on the bank's confirmation) builds on these rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payment_batches', function (Blueprint $table) {
            $table->id();

            // A batch pays one run. Restricted rather than cascaded: deleting a payroll run that
            // has already been paid out should be refused outright, not silently take the evidence
            // of the payment with it.
            $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->restrictOnDelete();

            // Human-facing identifier, the thing finance quotes to the bank ("PB-2026-03-0001").
            $table->string('reference', 60)->unique();

            // bank_transfer | cash | check — how this batch is being paid out. Per batch, not per
            // payment, because a bank file is generated for one method at a time.
            $table->string('method', 30)->default('bank_transfer');

            // pending  — built, not yet sent to the bank
            // exported — the bank file has been generated and downloaded
            // settled  — every payment in it has been confirmed
            // partial  — some settled, some failed or still outstanding
            $table->string('status', 20)->default('pending');

            // Denormalised so a batch list does not aggregate the payments table per row. Kept in
            // step by the service; the reconciliation assertion compares them against the run.
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->unsignedInteger('payment_count')->default(0);

            $table->timestamp('exported_at')->nullable();
            $table->foreignId('exported_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['payroll_run_id', 'status']);
        });

        Schema::create('hr_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_batch_id')->constrained('hr_payment_batches')->cascadeOnDelete();
            $table->foreignId('payslip_id')->constrained('hr_payslips')->restrictOnDelete();

            // Denormalised from the payslip so payment history can be listed per employee without
            // a join through payslips, which is the query HR actually asks for.
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('method', 30)->default('bank_transfer');

            // The account snapshot — see the note above on why this is copied and encrypted.
            // `text` because ciphertext is far longer than the plaintext it replaces.
            $table->text('bank_name')->nullable();
            $table->text('bank_account')->nullable();
            $table->text('bank_account_name')->nullable();
            $table->string('bank_account_type', 40)->nullable();

            // pending  — in a batch, not yet confirmed by the bank
            // settled  — the bank confirmed it; this is the only state that means money moved
            // failed   — the bank rejected it (bad account, closed account)
            // returned — it left and came back
            $table->string('status', 20)->default('pending');

            // The bank's own transaction reference, and the date it actually cleared. This is the
            // "actual pay date" the audit was careful NOT to let `scheduled_pay_date` pretend to
            // be: that column is a plan, this one is an observation.
            $table->string('reference', 100)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->string('failure_reason', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // A payslip cannot appear twice in the same batch. The stronger rule — a payslip is in
            // at most one LIVE batch — is enforced in the service instead: MySQL treats NULLs as
            // distinct in a unique index, so `unique(payslip_id, deleted_at)` would not actually
            // stop two live rows, and a plain `unique(payslip_id)` would make a soft-deleted batch
            // permanently block re-paying its run.
            $table->unique(['payment_batch_id', 'payslip_id']);
            $table->index(['employee_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payments');
        Schema::dropIfExists('hr_payment_batches');
    }
};
