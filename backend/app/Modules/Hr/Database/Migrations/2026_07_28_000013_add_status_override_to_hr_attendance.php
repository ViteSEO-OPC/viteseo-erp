<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 16 — HR can set the day's status outright, and the leave-credit refund needs a marker.
 *
 * `status` was always DERIVED from the punches (times → present/late/absent), so HR had no way to
 * say "she was on leave" or "he worked, he just forgot to clock in". `status_override` records
 * that a human asserted this status, so a later edit to the times doesn't quietly undo it.
 *
 * `leave_credit_refunded_at` makes the refund idempotent. When a paid-leave day turns out to have
 * been worked the credit goes back — but payroll re-runs constantly and attendance can be edited
 * repeatedly, so without a marker the balance would inflate a little every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance', function (Blueprint $table) {
            $table->boolean('status_override')->default(false)->after('status');
            $table->timestamp('leave_credit_refunded_at')->nullable()->after('status_override');
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance', function (Blueprint $table) {
            $table->dropColumn(['status_override', 'leave_credit_refunded_at']);
        });
    }
};
