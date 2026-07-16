<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('hr_leave_types');
            $table->integer('year');
            $table->decimal('entitled_days', 5, 1);
            $table->decimal('used_days', 5, 1)->default(0);
            $table->timestamps();
            $table->softDeletes();
            // One balance row per employee, per leave type, per year.
            $table->unique(['employee_id', 'leave_type_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_leave_balances');
    }
};
