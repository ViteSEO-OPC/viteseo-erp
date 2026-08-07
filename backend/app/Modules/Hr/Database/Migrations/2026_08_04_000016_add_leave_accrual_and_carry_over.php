<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave that grows, carries over and expires on its own (FIN-034, the `R` half / MF-10).
 *
 * Balances were seeded for the hire year and then edited by hand forever. Nothing accrued them
 * monthly, nothing rolled them into the next year, nothing expired what carried over. In practice
 * that means January arrives and every employee's leave silently reads zero until HR remembers to
 * re-enter the whole company by hand — and any figure typed twice is a figure that can be typed
 * wrong.
 *
 * ACCRUAL DEFAULTS TO OFF (`accrual_mode = 'none'`), which is the existing behaviour exactly. That is
 * deliberate and it is the same call as FIN-039's uncapped default: turning an engine on during a
 * migration would rewrite entitlements nobody asked it to touch, and leave entitlements are what
 * employees plan holidays around. HR switches each type over when they are ready.
 *
 * THE CARRY-OVER CAP AND EXPIRY ARE COMPANY POLICY, NOT LAW, so neither ships with a number. Philippine
 * law speaks only to the five-day Service Incentive Leave (Art. 95), which must be convertible to cash
 * if unused and cannot simply be forfeited; everything above it — how much vacation leave may cross a
 * year boundary, and for how long — is the employer's own rule. Inventing "10 days, expires in 3
 * months" would be the FIN-024 / FIN-030 mistake with an employee's accrued benefit as the casualty.
 * Null cap = everything carries; null expiry = carried days never lapse.
 *
 * WHY THE BALANCE ROW GAINS COMPONENTS RATHER THAN JUST A TOTAL. `entitled_days` stays the
 * authoritative figure every existing reader already uses, so nothing downstream changes. But it now
 * has to be re-derivable: `accrued_days` (what this year's accrual has credited so far) plus
 * `carried_days` (what came from last year). Storing the components is what makes the engine safe to
 * run twice — it recomputes the total from them instead of incrementing, so a cron that fires twice on
 * the 1st cannot hand out a double month. That is the same derive-don't-increment rule the loan ledger
 * and deduction arrears already follow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $table) {
            // 'none' (today's behaviour) | 'monthly' (days_per_year ÷ 12 each month) | 'annual'.
            $table->string('accrual_mode')->default('none')->after('days_per_year');
            // A mid-year hire accrues from their hire month, not the whole year. On by default
            // because the alternative — a full year's credit for a December start — is the kind of
            // thing nobody intends and everybody notices.
            $table->boolean('prorate_first_year')->default(true)->after('accrual_mode');
            // NULL = everything carries over. 0 = nothing does ("use it or lose it").
            $table->decimal('carry_over_cap_days', 5, 1)->nullable()->after('prorate_first_year');
            // NULL = carried days never expire. Otherwise months from 1 January.
            $table->unsignedTinyInteger('carry_over_expiry_months')->nullable()->after('carry_over_cap_days');
        });

        Schema::table('hr_leave_balances', function (Blueprint $table) {
            // The two halves `entitled_days` is now the sum of. See the class docblock: keeping them
            // is what lets the engine recompute rather than increment, and so run twice safely.
            $table->decimal('accrued_days', 5, 1)->default(0)->after('entitled_days');
            $table->decimal('carried_days', 5, 1)->default(0)->after('accrued_days');
            // When the carried portion lapses. NULL = never.
            $table->date('carried_expires_on')->nullable()->after('carried_days');
            // How far the monthly accrual has been credited, so a re-run is a no-op and a missed
            // month is caught up rather than skipped.
            $table->unsignedTinyInteger('accrued_through_month')->default(0)->after('carried_expires_on');
        });
    }

    public function down(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $table) {
            $table->dropColumn([
                'accrual_mode', 'prorate_first_year',
                'carry_over_cap_days', 'carry_over_expiry_months',
            ]);
        });

        Schema::table('hr_leave_balances', function (Blueprint $table) {
            $table->dropColumn([
                'accrued_days', 'carried_days', 'carried_expires_on', 'accrued_through_month',
            ]);
        });
    }
};
