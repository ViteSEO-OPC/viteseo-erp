<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice B19 — an allowance can be kept OUT of the statutory contribution base while still being
 * paid in full.
 *
 * WHY THIS IS A REAL SETTING AND NOT A LOOPHOLE. Once contributions are charged on actual earnings
 * (see the `salary_basis` migration alongside this one), every allowance raises both the employee's
 * and the employer's share. Companies routinely structure allowances so that some of them sit
 * outside the contributable base — the employee still receives the money, it simply is not
 * remuneration for contribution purposes. The agencies do not agree on a single list either: SSS
 * expressly INCLUDES the mandated COLA but excludes benefits under ₱90,000, so a COLA and a rice
 * subsidy cannot share one hardcoded answer.
 *
 * PER PROGRAMME, NOT COMPANY-WIDE, for exactly that reason: this is a per-allowance judgement HR
 * makes, the same shape as `tax_treatment`, which sits in the same row and is set on the same form.
 *
 * VERSIONED, because it prices a payslip. B11's rule for `hr_benefit_program_versions` is that
 * whatever decides what an allowance COSTS is versioned so an old cycle reprices to what it
 * actually paid — the amount, the frequency, the tax treatment, the ceiling. Whether the allowance
 * counted toward SSS is now one of those, and leaving it off the version would let a switch flipped
 * today rewrite what August's payslip was charged on.
 *
 * DEFAULT TRUE preserves today's behaviour on every existing allowance and every existing version:
 * an allowance counts unless someone deliberately says otherwise. Note that under the default
 * `salary_basis` of `basic` this flag does nothing at all — no allowance is in the base yet. It
 * starts mattering the moment a contribution is moved onto the earnings basis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_benefit_programs', function (Blueprint $table) {
            $table->boolean('contributable')->default(true)->after('ceiling_period');
        });

        Schema::table('hr_benefit_program_versions', function (Blueprint $table) {
            $table->boolean('contributable')->default(true)->after('ceiling_period');
        });
    }

    public function down(): void
    {
        Schema::table('hr_benefit_programs', function (Blueprint $table) {
            $table->dropColumn('contributable');
        });

        Schema::table('hr_benefit_program_versions', function (Blueprint $table) {
            $table->dropColumn('contributable');
        });
    }
};
