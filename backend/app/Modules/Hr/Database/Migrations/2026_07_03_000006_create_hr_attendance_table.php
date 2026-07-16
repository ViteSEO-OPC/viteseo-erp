<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            // The Manila calendar day this row belongs to (computed, not raw UTC).
            $table->date('work_date');
            $table->dateTime('time_in')->nullable();
            $table->dateTime('time_out')->nullable();
            $table->dateTime('lunch_started_at')->nullable();
            $table->dateTime('break_started_at')->nullable();
            $table->integer('lunch_minutes')->default(0);
            $table->integer('break_minutes')->default(0);
            $table->decimal('hours_worked', 5, 2)->default(0);
            $table->string('status')->default('present'); // present | late | absent
            $table->string('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['employee_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_attendance');
    }
};
