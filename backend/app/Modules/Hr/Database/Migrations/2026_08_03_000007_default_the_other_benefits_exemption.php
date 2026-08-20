<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The ₱90,000 exemption must exist on a fresh install, not be typed in. */
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
