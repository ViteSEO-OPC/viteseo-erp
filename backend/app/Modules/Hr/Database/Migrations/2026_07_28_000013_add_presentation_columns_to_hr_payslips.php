<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Payslip redesign — the three figures the document needs that the engine never persisted. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->decimal('period_base', 12, 2)->default(0)->after('base_salary');
            $table->decimal('absence_deduction', 12, 2)->default(0)->after('period_base');
            $table->json('allowances_breakdown')->nullable()->after('allowances');
        });

        // Backfill existing payslips. `period_base − absence_deduction = base_salary` holds by
        // construction, so the document reconciles on old rows too. For a row that clamped at
        // zero both figures are overstated by the same amount — the arithmetic is still right,
        // and there is no way to recover the true period base after the fact.
        DB::table('hr_payslips')->update([
            'period_base' => DB::raw('base_salary + (unpaid_days * daily_rate)'),
            'absence_deduction' => DB::raw('unpaid_days * daily_rate'),
        ]);

        // `allowances_breakdown` is deliberately left NULL: the split is unknowable for a past
        // period. The payslip falls back to a single "Allowances" line for those.
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn(['period_base', 'absence_deduction', 'allowances_breakdown']);
        });
    }
};
