<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-employee overrides for the schedule break minutes, matching the existing
// custom_* pattern (custom_start_time, custom_grace_minutes, …). Null = fall back to
// the assigned schedule. Half-day windows are template-only this pass — no custom_*.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->unsignedSmallInteger('custom_unpaid_break_minutes')->nullable()->after('custom_grace_minutes');
            $table->unsignedSmallInteger('custom_paid_break_minutes')->nullable()->after('custom_unpaid_break_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn(['custom_unpaid_break_minutes', 'custom_paid_break_minutes']);
        });
    }
};
