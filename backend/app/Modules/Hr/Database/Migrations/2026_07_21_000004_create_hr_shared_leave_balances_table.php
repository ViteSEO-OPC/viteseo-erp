<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Shared-mode per-employee pool: one balance per employee per year, spent by any
// paid leave type. Source of truth in shared mode (mirrors hr_leave_balances,
// minus the leave-type dimension).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_shared_leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->integer('year');
            $table->decimal('entitled_days', 5, 1);
            $table->decimal('used_days', 5, 1)->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['employee_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_shared_leave_balances');
    }
};
