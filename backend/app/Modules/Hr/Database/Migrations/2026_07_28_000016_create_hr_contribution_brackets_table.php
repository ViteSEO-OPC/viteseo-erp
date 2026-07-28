<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice B3b — SSS Monthly Salary Credit brackets.
 *
 * SSS does not charge a percentage of actual salary. A salary falls into a bracket, and the
 * bracket's MSC is what the rate applies to: earn ₱20,100 and the base is exactly ₱20,000, not
 * ₱20,100. Percentage-of-salary is wrong for almost everyone, in both directions, on every
 * payslip — and wrong for the employer remittance that actually gets filed.
 *
 * ONE BOUNDARY PER ROW, not two. Each bracket stores `min_salary` and runs up to the next
 * bracket's `min_salary`; the highest runs to infinity (bounded by the version's `salary_cap`).
 * Storing min AND max invites gaps and overlaps that have to be validated against — with a single
 * boundary they cannot be expressed at all. Same reasoning as `effective_from` having no end date.
 *
 * Brackets hang off a VERSION, so they are date-effective for free: a new schedule is a new
 * version with its own rows, and old periods keep pricing on the old table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_contribution_brackets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contribution_version_id')
                ->constrained('hr_contribution_versions')
                ->cascadeOnDelete();
            // The salary at which this bracket starts. The lowest must be 0 so every salary lands
            // somewhere; the bracket ends where the next one begins.
            $table->decimal('min_salary', 12, 2);
            // The Monthly Salary Credit the rates are applied to for salaries in this bracket.
            $table->decimal('msc', 12, 2);
            $table->timestamps();

            // Named explicitly: Laravel's generated name for these two columns is 65 characters,
            // one over MySQL's 64-char identifier limit. The table would be created and the index
            // would fail — and MySQL doesn't roll back DDL, leaving a half-migrated table behind.
            // The unique index also serves the lookup, so no separate index is needed.
            $table->unique(['contribution_version_id', 'min_salary'], 'hr_contrib_bracket_version_min_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_contribution_brackets');
    }
};
