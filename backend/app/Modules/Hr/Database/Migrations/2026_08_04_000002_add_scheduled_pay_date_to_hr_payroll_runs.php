<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A payroll run records the date it was scheduled to pay. */
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
