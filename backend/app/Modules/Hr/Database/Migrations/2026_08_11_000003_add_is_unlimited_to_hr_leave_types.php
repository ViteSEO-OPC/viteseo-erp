<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Unlimited" becomes a decision HR makes, instead of a side effect of not being paid.
 *
 * Until now the two were the same switch: `LeaveService` skipped the balance check for any type
 * with `is_paid = false`, so unpaid meant uncapped and there was no way to say otherwise. That
 * conflated two independent questions:
 *
 *   - IS IT PAID?      does payroll dock the day  (a pay question)
 *   - IS IT CAPPED?    does it draw down credits  (an entitlement question)
 *
 * All four combinations are real. Unpaid-and-capped is "you may take at most 10 LWOP days a year"
 * — the common case that could not be expressed at all. Paid-and-uncapped is unlimited PTO.
 *
 * BACKFILL. Every existing unpaid type is marked unlimited, which is exactly what it was doing
 * yesterday, so nothing changes behaviour on deploy. Paid types default to capped, likewise
 * unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $table) {
            $table->boolean('is_unlimited')->default(false)->after('is_paid');
        });

        // Preserve today's behaviour rather than reset it: unpaid == unlimited, until HR says otherwise.
        DB::table('hr_leave_types')->where('is_paid', false)->update(['is_unlimited' => true]);
    }

    public function down(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $table) {
            $table->dropColumn('is_unlimited');
        });
    }
};
