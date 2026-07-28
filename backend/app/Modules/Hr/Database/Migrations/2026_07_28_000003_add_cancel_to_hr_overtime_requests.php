<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 6 (2026-07-28 audit) — let HR undo an approved overtime request.
 *
 * `approve()`/`reject()` both guard on `pending`, so an approved request was frozen: if the
 * work never happened, the overtime was paid anyway and nobody could stop it. Mirrors the
 * leave-cancel columns (`cancelled_at` / `cancelled_by` / `cancel_reason`) so both request
 * flows read the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_overtime_requests', function (Blueprint $table) {
            $table->dateTime('cancelled_at')->nullable()->after('reviewed_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')
                ->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('hr_overtime_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
    }
};
