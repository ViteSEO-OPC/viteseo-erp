<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SSS Monthly Salary Credit brackets. */
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
