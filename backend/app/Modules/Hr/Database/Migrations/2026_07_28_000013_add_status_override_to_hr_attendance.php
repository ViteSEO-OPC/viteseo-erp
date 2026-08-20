<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** HR can set the day's status outright, and the leave-credit refund needs a marker. */
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
