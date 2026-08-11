<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cancellation trail for half-day and early-out requests.
 *
 * The last two request types on the employee's Work Schedule screen with no way out. Leave has
 * had one since Slice 21 and overtime since Slice 6, so a half-day filed for the wrong date could
 * only be undone by asking HR to DECLINE it — recording a refusal against the employee instead of
 * a withdrawal by them.
 *
 * Same three columns and same meaning as `hr_leave_requests` and `hr_overtime_requests`.
 */
return new class extends Migration
{
    private const TABLES = ['hr_half_day_requests', 'hr_early_out_requests'];

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
