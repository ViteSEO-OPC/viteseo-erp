<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The regional minimum daily wage, so a sub-minimum salary is visible. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_settings', function (Blueprint $table) {
            $table->decimal('minimum_daily_wage', 12, 2)->nullable()->after('other_benefits_annual_cap');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payroll_settings', function (Blueprint $table) {
            $table->dropColumn('minimum_daily_wage');
        });
    }
};
