<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase-2 Slice 19. Employees request a document (payroll/leave/attendance/CoE);
// HR fulfils (optionally attaching a file) or rejects.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_document_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('type'); // payroll | leave | attendance | coe | other
            $table->text('reason')->nullable();
            $table->string('status')->default('pending'); // pending | in_progress | fulfilled | rejected
            $table->string('fulfilled_path')->nullable();
            $table->string('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_document_requests');
    }
};
