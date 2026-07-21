<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cancellation trail for leave requests: who cancelled, when, and why.
// (status gains a 'cancelled' value — the column is a plain string, no change.)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_requests', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('review_note');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users');
            $table->string('cancel_reason')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('hr_leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
    }
};
