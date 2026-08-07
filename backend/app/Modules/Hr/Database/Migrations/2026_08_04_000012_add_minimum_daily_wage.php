<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The regional minimum daily wage, so a sub-minimum salary is visible (FIN-030).
 *
 * There was no minimum-wage rule anywhere in the module. `base_salary` was validated `min:0`, so a
 * salary producing a daily rate below the regional floor saved silently — and the daily rate is
 * derived (salary ÷ the basis in the attendance policy), so nobody typing a monthly figure can see
 * what it implies per day.
 *
 * IT LIVES ON `hr_payroll_settings`, which means it is EFFECTIVE-DATED for free (FIN-025). That
 * matters more here than for most settings: DOLE wage orders have dates, and a payslip re-run for
 * a period before an increase must be judged against the floor that applied then, not today's.
 *
 * NULL MEANS "NOT CONFIGURED", AND WARNS ABOUT NOTHING. The floor is regional and this system does
 * not know which region the company operates in, so seeding a figure would be guessing at a legal
 * threshold — the same mistake the de-minimis migration refused to make (FIN-024). HR enters the
 * wage order that applies to them.
 */
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
