<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employees', function (Blueprint $table) {
            $table->id();
            // 1-to-1 with the shared users table (login/roles live there).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('hr_departments');
            // Self-reference: an employee's manager is another employee.
            $table->foreignId('manager_id')->nullable()->constrained('hr_employees');
            $table->string('employee_no')->unique();
            $table->string('job_title');
            $table->string('employment_status')->default('regular');
            $table->date('hire_date');
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account')->nullable();
            $table->decimal('base_salary', 12, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employees');
    }
};
