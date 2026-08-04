<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll settings remember what they used to say (FIN-025).
 *
 * THE GAP, and it is the same one Company → Rules had until 2026-08-04. `hr_payroll_settings` is a
 * single mutable row holding `frequency`, `deduction_timing`, `tax_timing`, `anchor_date`,
 * `pay_delay_days` and `other_benefits_annual_cap`. `PayrollService::run()` read it live, so
 * re-running any cycle that was not locked or disbursed repriced it under today's settings.
 *
 * `frequency` is the sharp edge: it drives `periodsPerYear`, which divides annual pay into a
 * period amount and annualises the withholding base. Change it mid-year and a re-run of an
 * earlier period slices everyone's pay differently and annualises their tax against a different
 * number of periods. `deduction_timing` and `tax_timing` decide which cutoff in a month carries
 * the statutory deductions — flip one and a re-run can move a whole month's contributions onto a
 * different payslip. The run's `policy_snapshot` records these but does not replay them: it is
 * rewritten on every re-run, so it documents the drift rather than preventing it.
 *
 * SAME SHAPE AS THE ATTENDANCE POLICY VERSIONS, deliberately — whole row as a `payload`, one
 * `effective_from` and no end date, resolved as "the latest row on or before the cycle end".
 * Two date columns can disagree; one cannot. Storing the whole row rather than a hand-picked list
 * of pay-relevant fields is the same call and for the same reason: the hand-listed keys in
 * `policySnapshot()` already drifted once when Slice 10 added a settings field and nobody added
 * it there too.
 *
 * THE BACKFILL IS THE SAFETY. Today's settings are written as an opening version dated
 * 2000-01-01, so every existing run reprices to exactly what it already paid. Without it, the
 * first re-run after this migration would find no version and fall back to live settings — the
 * very behaviour being removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payroll_setting_versions', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from')->unique();
            // The whole settings row in DATABASE shape, so PayrollSetting::newFromBuilder()
            // rehydrates it with the casts applied exactly once on the way out.
            $table->json('payload');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $current = DB::table('hr_payroll_settings')->first();

        if ($current) {
            $payload = (array) $current;
            unset($payload['id'], $payload['created_at'], $payload['updated_at']);

            DB::table('hr_payroll_setting_versions')->insert([
                'effective_from' => '2000-01-01',
                'payload' => json_encode($payload),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payroll_setting_versions');
    }
};
