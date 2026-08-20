<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase-2 (Priority: High). A "Request Inquiry" from an employee's payslip
// view raises a payroll question for HR. HR moves it pending → in_progress → done,
// with an optional resolution note. Both request + resolution times are kept for
// turnaround/audit. (Telegram follow-up is out of scope — in-app only.)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payroll_inquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('payslip_id')->nullable()->constrained('hr_payslips')->nullOnDelete();
            $table->text('message');
            $table->string('priority')->default('high');
            $table->string('status')->default('pending'); // pending | in_progress | done
            $table->string('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payroll_inquiries');
    }
};
