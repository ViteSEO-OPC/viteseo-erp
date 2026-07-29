<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice B8 — the employer-only add-on (SSS's Employees' Compensation fund) belongs to the BAND,
 * not to a salary threshold.
 *
 * WHY. B3a modelled the EC as one amount plus an `add_on_min_salary` threshold, and it was entered
 * as "₱30 above ₱15,000" — half the rule. SSS actually charges **₱10 up to MSC ₱14,500 and ₱30
 * from MSC ₱15,000**, and it keys off the MSC, not the raw salary. Under the threshold form,
 * someone earning ₱14,900 (whose MSC is ₱15,000) would be charged ₱0 instead of ₱30, and everyone
 * below the threshold ₱0 instead of ₱10.
 *
 * The published schedule prints the EC per band, so importing the table settles it by construction
 * — no rule to get wrong, and it is date-effective along with the rest of the version. The
 * version-level `fixed_employer_add_on` stays as the fallback for a percentage-based version and
 * for any band that does not carry its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_contribution_brackets', function (Blueprint $table) {
            $table->decimal('employer_add_on', 12, 2)->nullable()->after('msc');
        });
    }

    public function down(): void
    {
        Schema::table('hr_contribution_brackets', function (Blueprint $table) {
            $table->dropColumn('employer_add_on');
        });
    }
};
