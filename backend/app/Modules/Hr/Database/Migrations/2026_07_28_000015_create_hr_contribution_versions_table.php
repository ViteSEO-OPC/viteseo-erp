<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Statutory contribution rates become dated versions instead of one editable row. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_contribution_versions', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // sss | philhealth | pagibig
            $table->date('effective_from');
            $table->string('basis')->default('percentage'); // percentage | bracket

            // Rates are stored as decimals (0.05 = 5%), matching the config they replace.
            $table->decimal('employee_rate', 8, 5)->default(0);
            $table->decimal('employer_rate', 8, 5)->default(0);

            // The salary the rate applies to, clamped into this window first.
            $table->decimal('salary_floor', 12, 2)->nullable();
            $table->decimal('salary_cap', 12, 2)->nullable();

            // A ceiling on the resulting AMOUNT (Pag-IBIG works this way).
            $table->decimal('employee_amount_cap', 12, 2)->nullable();
            $table->decimal('employer_amount_cap', 12, 2)->nullable();

            // SSS Employees' Compensation: a flat peso amount the EMPLOYER adds above a salary
            // threshold. Never deducted from the employee — see monthlyEmployerContribution().
            $table->decimal('fixed_employer_add_on', 12, 2)->default(0);
            $table->decimal('add_on_min_salary', 12, 2)->nullable();

            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['type', 'effective_from']);
            $table->index(['type', 'effective_from']);
        });

        // Backfill the current single-row config as the FIRST version, dated far enough back to
        // cover every existing run — so nothing reprices. Same safety trick as.
        foreach (DB::table('hr_contribution_settings')->get() as $setting) {
            $c = json_decode($setting->config ?? '{}', true) ?: [];

            DB::table('hr_contribution_versions')->insert([
                'type' => $setting->type,
                'effective_from' => '2000-01-01',
                'basis' => 'percentage',
                'employee_rate' => $c['rate'] ?? 0,
                'employer_rate' => $c['employer_rate'] ?? 0,
                'salary_floor' => $c['salary_floor'] ?? null,
                'salary_cap' => $c['salary_cap'] ?? null,
                'employee_amount_cap' => $c['cap'] ?? null,
                'employer_amount_cap' => $c['employer_cap'] ?? null,
                'fixed_employer_add_on' => 0,
                'add_on_min_salary' => null,
                'note' => $c['note'] ?? 'Migrated from the original single-row config.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_contribution_versions');
    }
};
