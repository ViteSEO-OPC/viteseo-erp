<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separation of duties on the payroll run (FIN-020).
 *
 * One role could edit a salary, run payroll, mark it disbursed, lock the run and unlock it again, with no
 * second party involved anywhere. That is the control finance auditors look for first, and the audit calls
 * it out as such: a single compromised HR account can raise a pay rate, pay it, and seal the period.
 *
 * WHAT THIS IS AND IS NOT. It is the ENFORCEMENT half — "a different person must do the second step."
 * Distinct `hr.payroll.*` permissions and a dedicated finance role are the other half of FIN-020, and they
 * live in `RolesAndPermissionsSeeder`, a shared file owned by the CTO. That half stays open and is flagged
 * as such; this half is entirely inside the HR module and needs nobody's sign-off.
 *
 * IT DEFAULTS OFF, and that is a real trade rather than timidity. Turning it on for an existing company
 * with one HR officer would leave them unable to disburse anything until somebody noticed the setting —
 * payroll blocked, on a payday. Off by default keeps every current install working, and the control is one
 * switch away with the UI stating what it requires. Unlike the overtime caps or leave accrual, this one
 * cannot silently change any figure: the worst case of leaving it off is the status quo the audit
 * describes, and the worst case of forcing it on is a company that cannot pay its staff.
 *
 * THE PROVENANCE WAS ALREADY THERE — `run_by`, `disbursed_by`, `locked_by` are all recorded. So even with
 * enforcement off, the trail needed to verify separation of duties after the fact exists. What was missing
 * was anything that acted on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_settings', function (Blueprint $table) {
            // When true, the user who ran a cycle cannot mark its payslips disbursed, and the user who
            // locked a run cannot unlock it.
            $table->boolean('require_maker_checker')->default(false)->after('minimum_daily_wage');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payroll_settings', function (Blueprint $table) {
            $table->dropColumn('require_maker_checker');
        });
    }
};
