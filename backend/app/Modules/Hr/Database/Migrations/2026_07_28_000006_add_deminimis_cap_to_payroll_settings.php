<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 10 (2026-07-28 audit) — the ceiling above which allowances stop being tax-free.
 *
 * Allowances are treated as non-taxable de-minimis pay with no limit at all. In reality each
 * de-minimis benefit has its own BIR ceiling and the excess is taxable (it joins the ₱90,000
 * 13th-month-and-other-benefits exclusion). Rather than hardcode a table of per-item limits we
 * can't keep current, HR sets one annual ceiling for the total.
 *
 * NULLABLE, and null means "no ceiling" — today's exact behaviour. Nothing changes until HR
 * sets a figure, so this ships switched off (build-steps rule 1).
 */
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
