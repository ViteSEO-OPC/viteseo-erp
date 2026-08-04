<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The withholding tax table becomes dated data instead of a `match` in PHP (FIN-027).
 *
 * THE GAP. Every other figure that prices a payslip is effective-dated — salaries (Slice 13c),
 * statutory contributions (B3a), allowances (B11), the attendance policy (2026-08-04). The BIR
 * graduated table was a `match (true)` literal inside `PayrollService::annualWithholdingTax()`.
 * Three consequences: a bracket change needs a code deploy, a historical period cannot be repriced
 * at the table that actually applied to it, and the figures themselves are invisible to HR — there
 * is no screen on which anyone could check them.
 *
 * SHAPE, deliberately the same as `hr_contribution_versions` + `hr_contribution_brackets`, which
 * this module already uses for SSS: `effective_from` only, no end date. Two date columns can
 * disagree — a gap leaves a period with no table, an overlap leaves two — whereas one start date
 * per row with "the latest row on or before the cycle end" makes both impossible.
 *
 * THE BRACKET ARITHMETIC is the BIR's own presentation, so a row can be read straight off the
 * published table and checked by eye:
 *
 *     tax = base_tax + rate_on_excess × (annual taxable income − min_annual)
 *
 * `min_annual` is where the band starts and it runs to the next band's start, so there is no
 * `max` column to keep in step — the same reasoning as the MSC bands.
 *
 * THE OPENING TABLE IS BACKFILLED HERE, not left to a seeder, for two reasons. Registering a
 * seeder means editing the shared `DatabaseSeeder`, which belongs to the platform rather than this
 * module. And more importantly a missing tax table would silently withhold ₱0 — the same class of
 * failure FIN-035 exists to prevent — so it must not depend on anyone remembering to run
 * something. `migrate` alone leaves the system correct.
 *
 * IT IS DATED 2000-01-01, NOT 2023-01-01, ON PURPOSE. The hardcoded `match` applied to every
 * period the system has ever priced, regardless of date, because there was no date to apply. An
 * opening row at 2000-01-01 reproduces that exactly, so introducing this table reprices nothing.
 * Dating it 2023-01-01 would leave every earlier cycle resolving to no table at all — a change in
 * behaviour dressed up as a correction. Entering the genuinely older schedules (the 2018–2022
 * tranche, and pre-TRAIN) is a separate job needing the real published figures; when they are
 * added with their correct dates, this row keeps everything before them working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_tax_versions', function (Blueprint $table) {
            $table->id();
            // The date this table took effect. Unique: one published table per start date.
            $table->date('effective_from')->unique();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('hr_tax_brackets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_version_id')->constrained('hr_tax_versions')->cascadeOnDelete();
            // Inclusive lower bound of the band, in annual pesos.
            $table->decimal('min_annual', 12, 2);
            // Tax due on everything below this band — the published "plus" figure.
            $table->decimal('base_tax', 12, 2)->default(0);
            // Marginal rate as a decimal (0.15 = 15%), applied to income above min_annual.
            $table->decimal('rate_on_excess', 6, 4)->default(0);
            $table->timestamps();

            // One band per starting point per table, and the index the resolver reads by.
            $table->unique(['tax_version_id', 'min_annual']);
        });

        // The TRAIN schedule in force from 1 January 2023 (RA 10963, second tranche) — the exact
        // figures that were hardcoded in PayrollService::annualWithholdingTax() before this.
        //
        //   over ₱0          up to ₱250,000     exempt
        //   over ₱250,000    up to ₱400,000     15% of the excess
        //   over ₱400,000    up to ₱800,000     ₱22,500 + 20% of the excess
        //   over ₱800,000    up to ₱2,000,000   ₱102,500 + 25% of the excess
        //   over ₱2,000,000  up to ₱8,000,000   ₱402,500 + 30% of the excess
        //   over ₱8,000,000                     ₱2,202,500 + 35% of the excess
        $versionId = DB::table('hr_tax_versions')->insertGetId([
            'effective_from' => '2000-01-01',
            'note' => 'TRAIN graduated rates (RA 10963), in force from 1 January 2023 — seeded as the opening table so no historical period reprices.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('hr_tax_brackets')->insert(array_map(
            fn (array $b) => [
                'tax_version_id' => $versionId,
                'min_annual' => $b[0],
                'base_tax' => $b[1],
                'rate_on_excess' => $b[2],
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                [0, 0, 0],
                [250000, 0, 0.15],
                [400000, 22500, 0.20],
                [800000, 102500, 0.25],
                [2000000, 402500, 0.30],
                [8000000, 2202500, 0.35],
            ],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_tax_brackets');
        Schema::dropIfExists('hr_tax_versions');
    }
};
