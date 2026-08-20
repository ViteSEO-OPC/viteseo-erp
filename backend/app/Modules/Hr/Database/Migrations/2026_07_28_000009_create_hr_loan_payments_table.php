<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The loan payment ledger. One row per (loan, payroll run). */
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
