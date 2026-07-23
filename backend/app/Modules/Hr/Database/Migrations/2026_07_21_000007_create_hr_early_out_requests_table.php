<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Early-out (undertime) requests are SEPARATE from leave and half-days: an employee
// leaves before their scheduled end on a given date. Always unpaid — payroll deducts
// the undertime fraction (end − time ÷ scheduled hours) for each APPROVED one (see
// AttendanceService::unpaidDaysFor). Mirrors the half-day approval flow.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_early_out_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->time('early_out_time'); // when they leave, HH:MM(:SS)
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
        Schema::dropIfExists('hr_early_out_requests');
    }
};
