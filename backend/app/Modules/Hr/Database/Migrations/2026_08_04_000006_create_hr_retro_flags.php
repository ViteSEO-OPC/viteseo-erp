<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Decisions made after the period they affect was already paid. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_retro_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();

            // The decision that caused it — a leave, overtime, half-day or early-out request.
            $table->morphs('flaggable');

            // The settled run whose period the decision reached into, kept so the flag can name
            // the cutoff rather than a bare date.
            $table->foreignId('payroll_run_id')->nullable()->constrained('hr_payroll_runs')->nullOnDelete();

            // owed     = the employee was underpaid for that period; settle with an adjustment run
            // overpaid = the employee was overpaid; recovery needs consent, so a person handles it
            $table->string('direction', 20);
            $table->string('reason', 500);

            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_note', 500)->nullable();

            $table->timestamps();

            // The queue HR works from: everything outstanding, oldest first.
            $table->index(['resolved_at', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_retro_flags');
    }
};
