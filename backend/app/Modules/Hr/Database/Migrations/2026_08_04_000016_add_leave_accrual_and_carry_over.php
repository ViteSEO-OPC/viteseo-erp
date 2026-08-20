<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Leave that grows, carries over and expires on its own (the `R` half /). */
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
