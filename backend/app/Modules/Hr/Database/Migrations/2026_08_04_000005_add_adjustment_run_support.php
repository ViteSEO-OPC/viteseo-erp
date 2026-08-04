<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Off-cycle adjustment runs — a way to pay a correction (FIN-010).
 *
 * THE GAP THIS CLOSES IS THE LARGEST IN THE AUDIT. There were exactly two write paths into pay: an
 * aligned regular period, and the December 13th-month run. Everything that could go wrong and be
 * discovered later — a back-dated raise (FIN-007), an attendance correction (FIN-008), a
 * late-approved overtime request (FIN-009), a reimbursement approved after its cycle closed
 * (FIN-023) — had nowhere to be paid. The only route was unlocking and re-running a closed period,
 * which rewrites history and is refused outright once payslips are disbursed. So corrections were
 * either impossible or dishonest.
 *
 * WHAT AN ADJUSTMENT RUN IS. An explicit list of employees and amounts, with a mandatory reason,
 * over a free-form date window. It does NOT recompute anything: it does not read attendance, does
 * not prorate, does not take statutory contributions (those are monthly and were already taken on
 * the period being corrected). It pays a stated amount and withholds tax on it.
 *
 * WHY THE WINDOW IS FREE-FORM. A regular run's dates ARE the period it prices. An adjustment's
 * dates are just when it was issued — what it corrects is described in the reason. Freeing the
 * window also sidesteps the `(cycle_start, cycle_end, frequency)` unique index cleanly: one
 * adjustment BATCH per window, holding as many employee lines as needed, and a second correction
 * for the same original period simply uses a later window.
 *
 * `reason` IS ON THE RUN, NOT THE LINE, and it is mandatory. An adjustment with no recorded reason
 * is indistinguishable from an error six months later, and this is the one run type where the
 * amount comes from a person rather than from the engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            // Why this run exists. Required for adjustments; null for regular and 13th-month runs,
            // whose reason for existing is the calendar.
            $table->string('reason', 500)->nullable()->after('type');
        });

        Schema::table('hr_payslips', function (Blueprint $table) {
            // The corrective amount, kept in its own column rather than folded into base_salary.
            // Back pay is not basic salary earned in the period this run covers, and putting it
            // there would quietly inflate the 13th-month basis, which is measured on base_salary.
            $table->decimal('adjustment_pay', 12, 2)->default(0)->after('thirteenth_month');
            // What this employee's line is for, so the payslip can explain itself.
            $table->string('adjustment_note', 500)->nullable()->after('adjustment_pay');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn(['adjustment_pay', 'adjustment_note']);
        });

        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
