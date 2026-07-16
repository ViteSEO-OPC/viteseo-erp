<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase-2 Slice 13. Default leave credits per employment status — used ONLY as a
// template when a new employee is created (EmployeeService::create). Each person's
// actual, editable credits live in hr_leave_balances (per-person override).
// Config table: no soft-deletes (rows are upserted by leave_type + status).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_leave_type_allotments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->cascadeOnDelete();
            $table->string('employment_status'); // regular | probationary | contract | part_time
            $table->decimal('days_per_year', 5, 1)->default(0);
            $table->timestamps();
            // One default per leave type, per employment status.
            $table->unique(['leave_type_id', 'employment_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_leave_type_allotments');
    }
};
