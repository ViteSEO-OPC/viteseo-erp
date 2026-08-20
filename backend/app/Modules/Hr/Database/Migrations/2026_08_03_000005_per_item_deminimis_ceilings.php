<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** De minimis is a ceiling PER BENEFIT, and the excess is not taxed directly. */
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
