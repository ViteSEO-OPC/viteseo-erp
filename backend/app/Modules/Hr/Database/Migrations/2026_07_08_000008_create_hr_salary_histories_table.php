<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase-2 Slice 17. Append-only log of an employee's base-salary changes, shown on
// the Compensation tab. EmployeeService writes a row on hire and whenever the
// salary changes. No soft-deletes — history is never edited or removed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_salary_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->decimal('base_salary', 12, 2);
            $table->date('effective_date');
            $table->string('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_salary_histories');
    }
};
