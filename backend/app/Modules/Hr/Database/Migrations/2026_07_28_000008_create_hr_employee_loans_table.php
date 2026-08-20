<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** (2026-07-28 audit) — loans and cash advances. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employee_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('type')->default('loan'); // loan | cash_advance | other
            $table->string('reference')->nullable(); // e.g. "COOP-2026-01"
            $table->decimal('principal', 12, 2);
            $table->decimal('per_period_amount', 12, 2);
            // Deductions start on the first pay period whose cycle END falls on/after this.
            $table->date('start_date');
            $table->string('status')->default('active'); // active | settled | cancelled | on_hold
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_loans');
    }
};
