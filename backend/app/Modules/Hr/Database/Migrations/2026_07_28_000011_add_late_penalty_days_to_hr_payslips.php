<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 13b item 13 — show the per-N-lates penalty on the payslip.
 *
 * `late_penalty_enabled` deducts a whole day for every N lates in the period. Those days folded
 * silently into `unpaid_days`, while the per-minute late/undertime charges each got their own
 * itemised line — so an employee could see "Unpaid days 2.00" with no way to tell that one of
 * them was a tardiness penalty rather than an absence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->decimal('late_penalty_days', 6, 2)->default(0)->after('undertime_deduction');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn('late_penalty_days');
        });
    }
};
