<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Slice B17 — the ₱90,000 exemption must exist on a fresh install, not be typed in.
 *
 * THE BUG THIS FIXES, caught by the B17 tests rather than by review. `other_benefits_annual_cap`
 * was nullable with no default, so a brand-new database had NO exemption recorded — and the
 * 13th-month run reads it as "the exemption is ₱0" and withholds tax on the whole thing. A
 * ₱30,000 13th month came out ₱3,000 short.
 *
 * It is also inconsistent, which is worse than either behaviour on its own: `payslipFor()` reads a
 * null cap as "no ceiling, tax nothing" while the 13th-month run read it as "no exemption, tax
 * everything". One column, two opposite meanings, both reachable.
 *
 * ₱90,000 is statutory (TRAIN), so it belongs in the schema as the default rather than in
 * somebody's memory of a setup checklist — the same failure B8 fixed for the SSS schedule, where
 * "correct" depended on someone retyping figures after every database rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('hr_payroll_settings')
            ->whereNull('other_benefits_annual_cap')
            ->update(['other_benefits_annual_cap' => 90000]);

        // MYSQL ONLY, and the guard is not optional. `ALTER TABLE … MODIFY` is MySQL syntax;
        // running it unguarded aborted the migration on sqlite, which is what `phpunit.xml` uses
        // — and that file is shared with the CRM and Projects pods, so an unportable migration
        // here took out 168 of THEIR tests with "no such table". A shared migration path means a
        // shared blast radius.
        //
        // Elsewhere the column simply stays nullable: `PayrollSetting::current()` writes the
        // ₱90,000 explicitly, so no code path can observe a missing exemption either way.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE hr_payroll_settings MODIFY other_benefits_annual_cap DECIMAL(12,2) NOT NULL DEFAULT 90000.00');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE hr_payroll_settings MODIFY other_benefits_annual_cap DECIMAL(12,2) NULL DEFAULT NULL');
        }
    }
};
