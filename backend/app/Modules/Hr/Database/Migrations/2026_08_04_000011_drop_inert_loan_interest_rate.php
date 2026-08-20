<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Removes `loan_interest_rate`, which was configurable and never charged. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_benefit_programs', function (Blueprint $table) {
            $table->dropColumn('loan_interest_rate');
        });
    }

    public function down(): void
    {
        Schema::table('hr_benefit_programs', function (Blueprint $table) {
            // Restored exactly as defined it, so a rollback is a true inverse.
            $table->decimal('loan_interest_rate', 6, 3)->nullable()->after('loan_term_periods');
        });
    }
};
