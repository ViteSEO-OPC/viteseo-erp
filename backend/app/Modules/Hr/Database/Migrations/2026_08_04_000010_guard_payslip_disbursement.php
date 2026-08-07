<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who declared a payslip paid, when, and against which request (FIN-033).
 *
 * `markDone()` is the single act in this system that asserts money left the company, and it had no
 * preconditions and no provenance. It did not check that the payslip was `computed`, that its run
 * had finished, or that it was not already disbursed; it recorded no actor and no timestamp; and
 * nothing stopped a double-submit from running it twice. For the one operation an auditor asks
 * about first, the honest answer to "who marked this paid?" was the audit log, if someone thought
 * to look, and "when?" was `updated_at`, which any later write would overwrite.
 *
 * `disbursement_key` IS THE IDEMPOTENCY GUARD. A repeated request carrying the same key is a
 * duplicate — a double-click, a retried POST, a re-imported reconciliation file — and is answered
 * as success without acting twice. A request with a DIFFERENT key against an already-disbursed
 * payslip is a genuine second attempt and is refused. The distinction matters: erroring on a
 * double-click teaches HR to ignore the error, and silently accepting a second disbursement is how
 * one payment becomes two.
 *
 * NOT UNIQUE-INDEXED, deliberately. A key is only meaningful against the payslip it was sent for,
 * and reversing a payslip clears it so the next genuine disbursement can proceed — a global unique
 * index would make a reversed-then-reissued payslip permanently unpayable by the same client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->timestamp('disbursed_at')->nullable()->after('status');
            $table->foreignId('disbursed_by')->nullable()->after('disbursed_at')
                ->constrained('users')->nullOnDelete();
            $table->string('disbursement_key', 100)->nullable()->after('disbursed_by');
        });

        // Existing disbursed payslips keep an honest gap rather than a guessed actor: `updated_at`
        // is not when they were disbursed, and inventing a `disbursed_by` would be worse than null.
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('disbursed_by');
            $table->dropColumn(['disbursed_at', 'disbursement_key']);
        });
    }
};
