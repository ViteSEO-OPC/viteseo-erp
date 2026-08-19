<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether anyone coming in on a suspended day has to ask first.
 *
 * A suspension has never blocked the time clock: a skeleton crew does come in during a typhoon,
 * and turning the button off would leave the people who actually worked with no record of it.
 * That stays the default (false) — it is what every existing declaration means.
 *
 * TRUE is the other half of the same concern. Some suspensions are safety decisions rather than
 * "the office is closed, do as you like": management wants to know who is in the building, and
 * an unattended button is how somebody drives through a flood to an empty office. On those,
 * pressing Time in files an `hr_work_requests` row of type `suspension` and HR decides — the
 * same held-punch mechanism rest-day work uses, so an approval hours later still stamps the
 * time-in at the moment the employee pressed the button.
 *
 * PER-DECLARATION, not a company rule, because the two kinds of suspension are genuinely
 * different days and HR is choosing between them each time. A company-wide setting would make
 * a flood and a scheduled shutdown answer the same way.
 *
 * Pay is untouched either way: an attendance row on a suspended day is worked and paid
 * normally, whichever route wrote it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_work_suspensions', function (Blueprint $table) {
            $table->boolean('work_requires_request')->default(false)->after('is_paid');
        });
    }

    public function down(): void
    {
        Schema::table('hr_work_suspensions', function (Blueprint $table) {
            $table->dropColumn('work_requires_request');
        });
    }
};
