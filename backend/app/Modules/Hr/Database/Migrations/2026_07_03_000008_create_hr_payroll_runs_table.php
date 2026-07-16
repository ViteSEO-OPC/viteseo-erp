<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->date('cycle_start');
            $table->date('cycle_end');
            $table->string('frequency')->default('semi_monthly');
            $table->string('status')->default('processing'); // processing | completed
            $table->decimal('total_gross', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('total_net', 14, 2)->default(0);
            $table->integer('employee_count')->default(0);
            $table->timestamp('run_at')->nullable();
            $table->foreignId('run_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payroll_runs');
    }
};
