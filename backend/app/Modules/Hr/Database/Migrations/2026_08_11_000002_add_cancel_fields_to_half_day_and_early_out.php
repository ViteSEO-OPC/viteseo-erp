<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cancellation trail for half-day and early-out requests. */
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
