<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cancellation trail for the three employee-facing requests that lacked one: claims, payroll
 * inquiries and concerns.
 *
 * Leave and overtime already let an employee withdraw a pending request with a reason
 * (`hr_leave_requests`, Slice 21). These three did not, so the only way out of a mistaken
 * submission was to ask HR to decline it — which records a rejection against the employee rather
 * than a withdrawal by them. Same three columns, same meaning, so the shape matches leave exactly.
 *
 * `status` gains a 'cancelled' value; every one of these columns is a plain string, so no change
 * is needed there. `ReimbursementService::nearDuplicateOf()` already excluded 'cancelled' from
 * duplicate matching, in anticipation of this.
 */
return new class extends Migration
{
    /** The tables getting the trail, in the order they appear in the employee's nav. */
    private const TABLES = ['hr_reimbursements', 'hr_payroll_inquiries', 'hr_concerns'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->timestamp('cancelled_at')->nullable();
                $blueprint->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $blueprint->string('cancel_reason')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('cancelled_by');
                $blueprint->dropColumn(['cancelled_at', 'cancel_reason']);
            });
        }
    }
};
