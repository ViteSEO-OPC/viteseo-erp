<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** (2026-07-28 audit) — let HR undo an approved overtime request. */
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
