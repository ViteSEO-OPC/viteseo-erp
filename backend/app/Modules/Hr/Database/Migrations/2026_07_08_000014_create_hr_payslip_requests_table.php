<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase-2 Slice 20. Employee requests an (official) payslip copy — must include a
// reason; HR approves or rejects.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payslip_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('payslip_id')->nullable()->constrained('hr_payslips')->nullOnDelete();
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
        Schema::dropIfExists('hr_payslip_requests');
    }
};
