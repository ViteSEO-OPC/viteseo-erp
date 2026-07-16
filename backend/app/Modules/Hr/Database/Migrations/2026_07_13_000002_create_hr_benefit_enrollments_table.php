<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-employee enrolment for benefit programs (pivot). An employee only sees a
// program on their "My Benefits" page when a row exists here — HR assigns people
// per program in the modal.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_benefit_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('benefit_program_id')->constrained('hr_benefit_programs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['benefit_program_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_benefit_enrollments');
    }
};
