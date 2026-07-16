<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Half-day requests are SEPARATE from leave: there are no paid half-days (you can't
// consume half a leave credit), so a half-day off is always unpaid and deducted from
// pay. Employee files one for a date + AM/PM; HR approves; payroll deducts 0.5 day.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_half_day_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->string('period'); // AM | PM
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
        Schema::dropIfExists('hr_half_day_requests');
    }
};
