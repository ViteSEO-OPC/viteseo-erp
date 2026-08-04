<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A payroll run records the date it was scheduled to pay (FIN-036).
 *
 * THE GAP. Nothing on `hr_payroll_runs` said when the money was due. `PayslipResource::payDate()`
 * DERIVED it as `cycle_end + pay_delay_days`, reading the delay from the run's `policy_snapshot`
 * when present and from the LIVE setting otherwise. So a payslip issued before the snapshot
 * column existed restated its own pay date whenever someone changed `pay_delay_days` — a document
 * the employee has a copy of, silently disagreeing with the copy the system shows.
 *
 * WHY `scheduled_`, AND NOT `pay_date`. There are two different dates here and conflating them is
 * exactly the error FIN-052 is about — reporting an intention as if it were a fact. This column is
 * the date the run was PLANNED to pay: fixed when the run is created, used by the payslip header
 * and by planning. The date money actually moved is not knowable by this system today; `markDone()`
 * is a manual assertion, not a settlement confirmation. That belongs on the payment records
 * FIN-015 introduces, together with the bank reference and the failure status, and naming this
 * column `pay_date` now would leave nowhere honest to put it.
 *
 * THE BACKFILL preserves what every existing payslip currently displays: `cycle_end` plus the
 * delay recorded in that run's own snapshot, falling back to the live setting for runs written
 * before snapshots existed. Runs keep showing the date they always showed; they simply stop being
 * able to change it afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $table->date('scheduled_pay_date')->nullable()->after('cycle_end');
        });

        $liveDelay = (int) (DB::table('hr_payroll_settings')->value('pay_delay_days') ?? 0);

        DB::table('hr_payroll_runs')->orderBy('id')->chunkById(200, function ($runs) use ($liveDelay) {
            foreach ($runs as $run) {
                $snapshot = json_decode($run->policy_snapshot ?? 'null', true);
                $delay = $snapshot['payroll_setting']['pay_delay_days'] ?? $liveDelay;

                DB::table('hr_payroll_runs')
                    ->where('id', $run->id)
                    ->update([
                        'scheduled_pay_date' => date('Y-m-d', strtotime($run->cycle_end . ' +' . (int) $delay . ' days')),
                    ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $table->dropColumn('scheduled_pay_date');
        });
    }
};
