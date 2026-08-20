<?php

use App\Modules\Hr\Support\StatutoryContributions;
use Illuminate\Database\Migrations\Migration;

/**
 * SSS / PhilHealth / Pag-IBIG exist in every installation by law, so they are installed here
 * rather than seeded. Before this, they arrived only through ContributionSettingSeeder — demo
 * data — so a deployment that ran `migrate` without `db:seed` showed an EMPTY "Government
 * mandated" section on Company → Benefits with no action to add them, and priced payroll with
 * no statutory deductions at all (no rows means nothing is `is_active`, so the rates-in-force
 * guard has nothing to complain about and every payslip pays gross).
 *
 * Idempotent, so it is safe on a database that was already seeded: firstOrCreate keys on
 * `type` and on (type, effective_from), and the SSS bands are only written to a version that
 * has none — a rate HR has since corrected through the import screen is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        StatutoryContributions::install();
    }

    public function down(): void
    {
        // Deliberately not reversible. Deleting these would take HR's own dated rate versions
        // and every enrolment hanging off them with it, to undo rows that are meant to exist in
        // every environment. Rolling back and re-migrating simply re-installs them.
    }
};
