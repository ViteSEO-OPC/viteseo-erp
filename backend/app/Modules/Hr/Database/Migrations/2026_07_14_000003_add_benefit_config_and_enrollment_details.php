<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Benefits modals: richer allowance config (frequency, tax treatment, eligibility),
 * HMO plan config (deduction cadence + coverage tiers), and per-employee assignment
 * details on the enrolment pivot (allowance amount, HMO tier + employee share).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_benefit_programs', function (Blueprint $table) {
            // Allowance config.
            $table->string('frequency', 20)->default('monthly')->after('amount');       // monthly | semi_monthly | ...
            $table->string('tax_treatment', 20)->default('de_minimis')->after('frequency'); // de_minimis | taxable
            $table->string('eligibility', 20)->default('all')->after('tax_treatment');    // all | regular | custom
            // HMO / company-benefit plan config.
            $table->string('deduction_cadence', 20)->nullable()->after('eligibility');     // per_cutoff | monthly
            $table->json('tiers')->nullable()->after('deduction_cadence');                 // [{name, monthly_premium, employee_share}]
        });

        Schema::table('hr_benefit_enrollments', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->nullable()->after('employee_id');   // allowance amount for this person
            $table->string('tier', 40)->nullable()->after('amount');              // HMO tier name
            $table->decimal('employee_share', 12, 2)->nullable()->after('tier');  // HMO deduction for this person
        });
    }

    public function down(): void
    {
        Schema::table('hr_benefit_programs', function (Blueprint $table) {
            $table->dropColumn(['frequency', 'tax_treatment', 'eligibility', 'deduction_cadence', 'tiers']);
        });
        Schema::table('hr_benefit_enrollments', function (Blueprint $table) {
            $table->dropColumn(['amount', 'tier', 'employee_share']);
        });
    }
};
