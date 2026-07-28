<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slice B3a — statutory contribution rates become dated versions instead of one editable row.
 *
 * These rates genuinely change: SSS went 14% → 15% in January 2025 with its ceiling moving
 * ₱30,000 → ₱35,000; PhilHealth climbed yearly from 3% (2020) to its 5% cap; Pag-IBIG doubled its
 * fund-salary basis to ₱10,000 in 2024. With a single row, changing a rate silently reprices every
 * payroll ever run, and an old period can never be reproduced.
 *
 * `effective_from` ONLY — no end date. Two date columns can disagree: a gap leaves a period with
 * no applicable rate, an overlap leaves two. "The latest row on or before the cycle end" makes
 * both impossible, and mirrors how `salariesAsOf()` already resolves salary.
 *
 * `basis` is here now but only `percentage` is implemented — SSS's Monthly Salary Credit brackets
 * are Slice B3b. The column exists so that slice is a reader, not a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_contribution_versions', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // sss | philhealth | pagibig
            $table->date('effective_from');
            $table->string('basis')->default('percentage'); // percentage | bracket (B3b)

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
        // cover every existing run — so nothing reprices. Same safety trick as Slice B1.
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
