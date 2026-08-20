<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** (2026-07-28 audit) — the ceiling above which allowances stop being tax-free. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_settings', function (Blueprint $table) {
            $table->decimal('deminimis_annual_cap', 12, 2)->nullable()->after('pay_delay_days');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payroll_settings', function (Blueprint $table) {
            $table->dropColumn('deminimis_annual_cap');
        });
    }
};
