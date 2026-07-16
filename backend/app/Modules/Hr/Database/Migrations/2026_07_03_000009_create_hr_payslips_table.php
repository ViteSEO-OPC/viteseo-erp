<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            // Earnings.
            $table->decimal('base_salary', 12, 2);
            $table->decimal('reimbursements', 12, 2)->default(0);
            $table->decimal('gross', 12, 2);
            // Statutory deductions.
            $table->decimal('sss', 12, 2)->default(0);
            $table->decimal('philhealth', 12, 2)->default(0);
            $table->decimal('pagibig', 12, 2)->default(0);
            $table->decimal('withholding_tax', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2);
            $table->string('status')->default('paid');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payslips');
    }
};
