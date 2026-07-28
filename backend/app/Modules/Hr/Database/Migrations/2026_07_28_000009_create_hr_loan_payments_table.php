<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 11b — the loan payment ledger. One row per (loan, payroll run).
 *
 * This is what makes the whole feature re-run safe. `unique(loan_id, payroll_run_id)` plus
 * updateOrCreate means recomputing a cycle REPLACES that period's payment instead of adding a
 * second one, so a balance derived as `principal − SUM(amount)` can never drift however many
 * times payroll is re-run. It also gives HR a payment history for free.
 *
 * `amount` may be less than the loan's per-period figure: when a payslip can't cover the full
 * instalment we take what's there and the remainder stays on the balance (product decision,
 * 2026-07-28) — so net pay never goes negative and the loan just settles a period later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_loan_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('hr_employee_loans')->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
            $table->foreignId('payslip_id')->nullable()->constrained('hr_payslips')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->unique(['loan_id', 'payroll_run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_loan_payments');
    }
};
