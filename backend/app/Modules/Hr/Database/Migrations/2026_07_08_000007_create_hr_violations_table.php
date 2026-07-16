<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase-2 Slice 16. Color-coded attendance violations derived from hr_attendance
// + the employee's schedule + the attendance policy. Regenerated idempotently by
// ViolationService::recompute() — one row per (employee, type, work_date); a row
// HR has marked resolved is preserved across recomputes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('type');      // late | ua | awol | missed_time_out
            $table->string('severity');  // green | yellow | orange | red
            $table->date('work_date');
            $table->string('details')->nullable();
            $table->boolean('resolved')->default(false);
            $table->timestamps();
            $table->unique(['employee_id', 'type', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_violations');
    }
};
