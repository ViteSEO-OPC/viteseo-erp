<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase-2. Per-employee contribution checklist — "not all employees have
// the same contributions." Payroll only applies a contribution to an employee when
// their row here is enrolled = true. Seeded enrolled for every active contribution
// on hire (EmployeeService::create), then HR toggles per person.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employee_benefits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('contribution_type'); // sss | philhealth | pagibig
            $table->boolean('enrolled')->default(true);
            $table->timestamps();
            $table->unique(['employee_id', 'contribution_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_benefits');
    }
};
