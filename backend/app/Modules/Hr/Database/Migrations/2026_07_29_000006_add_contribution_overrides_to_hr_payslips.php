<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice B7 — record which statutory deductions on this payslip came from a per-employee override
 * rather than the government rates.
 *
 * WHY. A peso amount typed into `sss_contribution` on a profile replaces the EMPLOYEE side only;
 * the employer side is still computed from the rates. The payslip then carries a pair that exists
 * on no SSS table — ₱1,350 employee against ₱3,530 employer — and nothing anywhere says why. A
 * remittance built from that pair cannot be filed, and the only way to find out was to open the
 * employee's profile.
 *
 * Stored as a snapshot on the payslip, not derived at display time, for the same reason the
 * allowance breakdown is: the profile can be edited afterwards, and an old payslip has to keep
 * explaining what it actually did.
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
