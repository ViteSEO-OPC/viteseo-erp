<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slice B16 — de minimis is a ceiling PER BENEFIT, and the excess is not taxed directly.
 *
 * WHAT WE HAD WRONG. One aggregate `deminimis_annual_cap` was applied to the total of every
 * de-minimis allowance, and anything above it went straight into taxable compensation. Two rules
 * were conflated, and neither was the one that exists:
 *
 *   1. Each de minimis benefit has its OWN statutory ceiling — rice ₱2,500/month, uniform
 *      ₱8,000/year, medical cash allowance ₱2,000/semester (BIR RR 29-2025). They cannot be
 *      pooled or offset against each other; a small uniform allowance does not buy room for a
 *      large rice subsidy.
 *   2. The ₱90,000 the UI suggested is NOT a de minimis figure at all. It is the tax-exempt
 *      ceiling for 13th-month pay AND OTHER BENEFITS (TRAIN, RA 10963).
 *
 * The chain is: amount over an item's own ceiling → joins the "13th month and other benefits"
 * bucket → that whole bucket is exempt up to ₱90,000 a year → only what is over ₱90,000 is
 * taxable compensation. We were skipping the middle step, which over-taxes the excess for
 * everybody whose benefits sit under ₱90,000 — the normal case.
 *
 * NOBODY'S PAY MOVES TODAY. Existing programmes get a NULL ceiling, which means "no statutory
 * limit recorded", and behave exactly as they do now. Deciding which BIR item an allowance is —
 * whether "Meal Allowance" is the de-minimis meal benefit or ordinary taxable pay — is HR's call
 * on real tax facts, not something a migration should guess.
 *
 * `deminimis_annual_cap` is RENAMED to `other_benefits_annual_cap`, because that is what ₱90,000
 * governs. A field whose name states the wrong rule is how the wrong rule gets implemented twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_benefit_programs', function (Blueprint $table) {
            // The statutory limit for THIS benefit. Null = none recorded ⇒ the whole amount keeps
            // its current treatment.
            $table->decimal('deminimis_ceiling', 12, 2)->nullable()->after('tax_treatment');
            // The period the ceiling is stated in — the BIR states them variously per month, per
            // semester and per year, and normalising by hand is where transcription errors live.
            $table->string('ceiling_period', 20)->nullable()->after('deminimis_ceiling');
        });

        Schema::table('hr_payroll_settings', function (Blueprint $table) {
            $table->renameColumn('deminimis_annual_cap', 'other_benefits_annual_cap');
        });

        // The statutory figure, so a fresh install is right without anyone knowing to type it.
        // An existing value is left alone: if HR set something deliberately, it is theirs.
        DB::table('hr_payroll_settings')
            ->whereNull('other_benefits_annual_cap')
            ->update(['other_benefits_annual_cap' => 90000]);
    }

    public function down(): void
    {
        Schema::table('hr_payroll_settings', function (Blueprint $table) {
            $table->renameColumn('other_benefits_annual_cap', 'deminimis_annual_cap');
        });

        Schema::table('hr_benefit_programs', function (Blueprint $table) {
            $table->dropColumn(['deminimis_ceiling', 'ceiling_period']);
        });
    }
};
