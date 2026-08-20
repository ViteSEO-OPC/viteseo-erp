<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record which statutory deductions on this payslip came from a per-employee override
 * rather than the government rates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->json('contribution_overrides')->nullable()->after('employer_pagibig');
        });

        // Existing payslips are deliberately left NULL rather than backfilled from the profile:
        // the profile is today's state, and guessing that it applied when the run happened is
        // exactly the kind of retro-fitted fiction this column exists to prevent.
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn('contribution_overrides');
        });
    }
};
