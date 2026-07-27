<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Overtime requests (Premium Pay epic, Phase 1 / OT-1). An employee requests N overtime HOURS on a
// date; payroll pays each APPROVED one at the configured OT rate (see PayrollService, OT-3). Mirrors
// the early-out (undertime) request flow — opposite sign (positive pay, not a deduction).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->decimal('hours', 4, 2); // requested overtime hours, e.g. 2.50
            $table->text('reason');
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->string('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_overtime_requests');
    }
};
