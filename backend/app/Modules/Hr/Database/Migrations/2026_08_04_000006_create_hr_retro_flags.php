<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decisions made after the period they affect was already paid (FIN-009).
 *
 * THE SITUATION. Leave, overtime, half-day and early-out requests were freeze-guarded only in
 * `reopen()` — never in `approve()`, and `OvertimeRequestService::cancel()` accepted an already
 * APPROVED request with no check at all. So HR could approve a back-dated overtime request for a
 * cutoff that had already been paid, or cancel one that had already been paid out, and payroll
 * would never know.
 *
 * WHY NOT SIMPLY BLOCK IT — the obvious fix, and the wrong one. The leave was taken. The overtime
 * was worked. Refusing the approval does not undo the fact; it just leaves the attendance record
 * permanently wrong AND the pay wrong, instead of only the pay. `OvertimeRequestService::cancel()`
 * already said this out loud in its own docblock: "HR settles the difference manually rather than
 * have pay silently move under a payslip someone has already been given." The honest gap was that
 * "manually" had no workflow, no record and no reconciliation — the audit's phrase was that it is
 * "FIN-010 by another name".
 *
 * SO THE DECISION IS ALLOWED AND THE MONEY IS TRACKED. The record becomes correct immediately; a
 * row here says a payroll difference is outstanding, which direction it runs, and against which
 * settled run. FIN-010's adjustment run is how an `owed` flag gets paid, and resolving the flag
 * records that it was.
 *
 * `overpaid` deliberately has no automated path. Recovering money already paid is a wage deduction
 * and needs the employee's written consent under Art. 113 — the same ground as FIN-002 — so it is
 * surfaced for a person to handle, not quietly netted off a future payslip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_retro_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();

            // The decision that caused it — a leave, overtime, half-day or early-out request.
            $table->morphs('flaggable');

            // The settled run whose period the decision reached into, kept so the flag can name
            // the cutoff rather than a bare date.
            $table->foreignId('payroll_run_id')->nullable()->constrained('hr_payroll_runs')->nullOnDelete();

            // owed     = the employee was underpaid for that period; settle with an adjustment run
            // overpaid = the employee was overpaid; recovery needs consent, so a person handles it
            $table->string('direction', 20);
            $table->string('reason', 500);

            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_note', 500)->nullable();

            $table->timestamps();

            // The queue HR works from: everything outstanding, oldest first.
            $table->index(['resolved_at', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_retro_flags');
    }
};
