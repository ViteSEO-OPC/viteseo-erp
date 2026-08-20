<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The second per-day rule override, beside `grace_minutes`: the minutes an employee must
// actually work for the day to count WHOLE, regardless of when they clocked out. Set when a
// DOLE advisory (or the company) shortens the compensable day — "six hours is a full day
// today" — and cleared the next.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_daily_overrides', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_full_day_minutes')->nullable()->after('grace_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('hr_daily_overrides', function (Blueprint $table) {
            $table->dropColumn('min_full_day_minutes');
        });
    }
};
